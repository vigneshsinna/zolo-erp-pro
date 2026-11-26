<?php

declare(strict_types=1);

namespace App\Ai\Communications;

use App\Ai\Adapters\CustomerCollectionAdapter;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Infrastructure\WorkerActorContext;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\ActionSecurity;
use App\Ai\Services\AiPrincipalFactory;
use App\Ai\Services\CanonicalActionPayload;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/** A separately approved, encrypted outbox. Preparing a collection draft never schedules delivery. */
final class PaymentReminderService
{
    public function preview(ActionDefinition $definition, array $slots, CompanyContext $context): ActionPreviewResult
    {
        $this->assertEnabled();
        $company = Company::findOrFail($context->companyId);
        $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();
        if (($slots['date'] ?? '') !== $today) {
            throw ValidationException::withMessages(['date' => 'A payment reminder must use the current company date.']);
        }
        $base = app(CustomerCollectionAdapter::class)->preview($definition, $slots, $context);
        $customer = Customer::where('company_id', $context->companyId)->where('is_active', true)->findOrFail($slots['customer_id']);
        $recipient = trim((string) $customer->email);
        validator(['email' => $recipient], ['email' => 'required|email:rfc|max:191'])->validate();
        $channel = DB::table('document_channels')->where('company_id', $context->companyId)
            ->where('channel', 'email')->where('is_active', true)->first();
        if (!$channel) throw ValidationException::withMessages(['email' => 'Configure an active company email delivery channel first.']);
        $invoice = $base->preview['invoice_reference'];
        $subject = mb_substr(preg_replace('/[\r\n]+/', ' ', 'Payment reminder: '.$invoice), 0, 200);
        $body = $company->name."\n\nPayment reminder for invoice ".$invoice.'.'
            ."\nOpen amount as of ".$today.': '.$base->preview['open_amount'].'.'
            ."\nPlease contact us if payment is already in progress.";
        $payload = array_intersect_key($base->payload, array_flip(['customer_id', 'open_item_id', 'date', 'note'])) + [
            'recipient_email' => $recipient, 'open_amount' => $base->preview['open_amount'],
            'subject' => $subject, 'message' => $body,
        ];
        $hash = hash('sha256', json_encode([$base->sourceStateHash, $company->name, $channel->id,
            $channel->configuration_encrypted], JSON_THROW_ON_ERROR));
        return new ActionPreviewResult($payload, [
            'document_type' => 'Outbound Payment Reminder', 'customer_name' => $customer->name,
            'invoice_reference' => $invoice, 'open_amount' => $payload['open_amount'], 'date' => $today,
            'recipient_email' => $recipient, 'subject' => $subject, 'message' => $body,
            'policy' => 'Approval authorizes one email to the customer master address. Delivery is queued and revalidated before sending.',
            'external_sending' => 'email_after_approval',
        ], $hash);
    }

    public function enqueue(ActionDefinition $definition, array $payload, CompanyContext $context, int $actor,
        string $key, string $expectedHash): ActionExecutionReceipt
    {
        $this->assertEnabled();
        $connection = (string) config('ai_communications.queue_connection', 'redis');
        if (config("queue.connections.{$connection}.driver") === 'sync') {
            throw ValidationException::withMessages(['queue' => 'Payment reminder delivery requires an asynchronous worker connection.']);
        }
        $context = app(CompanyContextResolver::class)->forActor($context, $actor);
        app(CommercialPermission::class)->assert('documents-dispatch', $context, $actor);
        return DB::transaction(function () use ($definition, $payload, $context, $actor, $key, $expectedHash) {
            Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            if ($receipt = $this->recover($key, $context)) return $receipt;
            if (!preg_match('/^ai-action:([a-f0-9-]{36}):v([1-9][0-9]*)$/D', $key, $match)) {
                throw new AuthorizationException('A sealed action execution is required.');
            }
            $proposal = DB::table('ai_action_proposals')->where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)->where('financial_year_id', $context->financialYearId)
                ->where('id', $match[1])->where('version', (int) $match[2])->lockForUpdate()->first();
            $this->assertProposal($proposal, $payload, $context);
            $this->lockSources($payload, $context);
            $fresh = $this->preview($definition, $payload, $context);
            if (!hash_equals($expectedHash, $fresh->sourceStateHash)
                || !hash_equals(CanonicalActionPayload::hash($payload), CanonicalActionPayload::hash($fresh->payload))) {
                throw ValidationException::withMessages(['source_state' => 'Recipient, invoice balance or message changed. Prepare a new reminder.']);
            }
            $count = DB::table('ai_reminder_outbox')->where('company_id', $context->companyId)
                ->where('created_at', '>=', now('UTC')->startOfDay())->count();
            if ($count >= max(1, (int) config('ai_communications.daily_company_limit', 100))) {
                throw ValidationException::withMessages(['reminder' => 'The company daily reminder limit has been reached.']);
            }
            $id = DB::table('ai_reminder_outbox')->insertGetId([
                'company_id' => $context->companyId, 'branch_id' => $context->branchId, 'financial_year_id' => $context->financialYearId,
                'actor_id' => $actor, 'customer_id' => $payload['customer_id'], 'open_item_id' => $payload['open_item_id'],
                'proposal_id' => $proposal->id, 'proposal_version' => $proposal->version, 'idempotency_key' => $key,
                'payload_hash' => CanonicalActionPayload::hash($payload), 'source_state_hash' => $expectedHash,
                'payload_encrypted' => Crypt::encryptString(CanonicalActionPayload::json($payload)), 'status' => 'queued',
                'expires_at' => $proposal->expires_at, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            DB::afterCommit(function () use ($context, $id): void {
                try { Bus::dispatch(new DeliverPaymentReminder($context->companyId, $id)); }
                catch (Throwable $error) { report($error); } // Durable queued row can be dispatched by the recovery command.
            });
            return new ActionExecutionReceipt('payment_reminder_delivery', $id, 'queued', ['delivery_status' => 'queued', 'external_sending' => 'email']);
        });
    }

    public function recover(string $key, CompanyContext $context): ?ActionExecutionReceipt
    {
        $row = DB::table('ai_reminder_outbox')->where('company_id', $context->companyId)->where('branch_id', $context->branchId)
            ->where('financial_year_id', $context->financialYearId)->where('idempotency_key', $key)->first();
        return $row ? new ActionExecutionReceipt('payment_reminder_delivery', (int) $row->id, $row->status,
            ['delivery_status' => $row->status, 'external_sending' => 'email']) : null;
    }

    public function deliver(int $companyId, int $id): void
    {
        if (!config('ai_communications.reminders_enabled') || !config('ai_actions.execution_enabled')) return;
        $row = DB::table('ai_reminder_outbox')->where('company_id', $companyId)->where('id', $id)->first();
        if (!$row || $row->status !== 'queued') return;
        try {
            $reference = new AiPrincipal(actorId: (int) $row->actor_id, companyId: $companyId,
                branchId: (int) $row->branch_id, financialYearId: (int) $row->financial_year_id);
            app(WorkerActorContext::class)->run($reference, function (AiPrincipal $principal) use ($row, $companyId, $id): void {
                $context = app(ActionSecurity::class)->context($principal);
                $definition = app(ActionSecurity::class)->definition('payment_reminder.send.v1', $principal);
                $payload = json_decode(Crypt::decryptString($row->payload_encrypted), true, flags: JSON_THROW_ON_ERROR);
                $claimed = DB::transaction(function () use ($row, $payload, $context, $definition): bool {
                    Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
                    $current = DB::table('ai_reminder_outbox')->where('company_id', $context->companyId)->where('id', $row->id)->lockForUpdate()->first();
                    if (!$current || $current->status !== 'queued') return false;
                    $proposal = DB::table('ai_action_proposals')->where('company_id', $context->companyId)
                        ->where('id', $row->proposal_id)->where('version', $row->proposal_version)->first();
                    $this->assertProposal($proposal, $payload, $context);
                    $this->lockSources($payload, $context);
                    $fresh = $this->preview($definition, $payload, $context);
                    if (!hash_equals($row->payload_hash, CanonicalActionPayload::hash($payload))
                        || !hash_equals($row->source_state_hash, $fresh->sourceStateHash)
                        || !hash_equals($row->payload_hash, CanonicalActionPayload::hash($fresh->payload))) {
                        throw ValidationException::withMessages(['source_state' => 'The approved reminder source has changed.']);
                    }
                    DB::table('ai_reminder_outbox')->where('company_id', $context->companyId)->where('id', $row->id)->update([
                        'status' => 'sending', 'attempts' => (int) $current->attempts + 1, 'attempted_at' => now('UTC'), 'updated_at' => now('UTC'),
                    ]);
                    return true;
                });
                if (!$claimed) return;
                try {
                    $channel = DB::table('document_channels')->where('company_id', $companyId)->where('channel', 'email')->where('is_active', true)->firstOrFail();
                    $settings = json_decode(Crypt::decryptString($channel->configuration_encrypted), true, flags: JSON_THROW_ON_ERROR);
                    validator(['from' => $settings['from'] ?? null], ['from' => 'required|email:rfc|max:191'])->validate();
                    // The custom outbox is the queue boundary. A second mail queue would obscure the delivery outcome.
                    $sent = Mail::raw($payload['message'], fn ($message) => $message->to($payload['recipient_email'])
                        ->from($settings['from'])->subject($payload['subject']));
                    $this->status($companyId, $id, 'sending', $sent instanceof \Illuminate\Mail\SentMessage ? 'sent' : 'not_sent',
                        $sent instanceof \Illuminate\Mail\SentMessage ? null : 'transport_suppressed');
                } catch (Throwable) {
                    $this->status($companyId, $id, 'sending', 'delivery_unknown', 'transport');
                }
            });
        } catch (Throwable) {
            $this->status($companyId, $id, 'queued', 'requires_revalidation', 'access_or_source_changed');
        }
    }

    private function assertProposal(?object $proposal, array $payload, CompanyContext $context): void
    {
        if (!$proposal || $proposal->action_key !== 'payment_reminder.send.v1'
            || (int) $proposal->branch_id !== $context->branchId || (int) $proposal->financial_year_id !== $context->financialYearId
            || !in_array($proposal->status, ['approved', 'executing', 'completed'], true)
            || CarbonImmutable::parse($proposal->expires_at, 'UTC')->isPast()
            || !hash_equals($proposal->payload_hash, CanonicalActionPayload::hash($payload))) {
            throw new AuthorizationException('A current sealed payment-reminder approval is required.');
        }
        $approval = app(ActionApprovalService::class)->assertApproved($proposal);
        $owner = app(AiPrincipalFactory::class)->createForUser((int) $proposal->actor_id, $context->companyId,
            $context->branchId, $context->financialYearId);
        app(ActionSecurity::class)->definition('payment_reminder.send.v1', $owner);
        $principal = app(AiPrincipalFactory::class)->createForUser((int) $approval->approver_id, $context->companyId,
            $context->branchId, $context->financialYearId);
        app(ActionSecurity::class)->definition('payment_reminder.send.v1', $principal);
        app(CommercialPermission::class)->assert('ai.action.approve', $context, $principal->actorId);
    }

    private function lockSources(array $payload, CompanyContext $context): void
    {
        DB::table('customers')->where('company_id', $context->companyId)->where('id', $payload['customer_id'])->lockForUpdate()->first();
        DB::table('account_open_items')->where('company_id', $context->companyId)->where('id', $payload['open_item_id'])->lockForUpdate()->first();
        DB::table('document_channels')->where('company_id', $context->companyId)->where('channel', 'email')->lockForUpdate()->get();
    }

    private function assertEnabled(): void
    {
        if (!config('ai_communications.reminders_enabled', false)) throw new AuthorizationException('Outbound AI payment reminders are disabled.');
    }

    private function status(int $companyId, int $id, string $expected, string $status, ?string $failure): void
    {
        DB::table('ai_reminder_outbox')->where('company_id', $companyId)->where('id', $id)->where('status', $expected)
            ->update(['status' => $status, 'failure_category' => $failure, 'updated_at' => now('UTC')]
                + ($status === 'sent' ? ['sent_at' => now('UTC')] : []));
    }
}
