<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Commercial;

use App\Ai\Communications\DeliverPaymentReminder;
use App\Ai\Communications\PaymentReminderService;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\ApprovedActionExecutor;
use App\Models\Accounting\AccountOpenItem;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\Accounting\PartyOpeningBalanceService;
use App\Services\Platform\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Email;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class PaymentReminderDispatchTest extends TestCase
{
    use UsesAiMysql;

    private AiPrincipal $principal;
    private CompanyContext $context;
    private Customer $customer;
    private int $openItemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(10, 0));
        config(['erp_ai.enabled' => true, 'erp_ai.read_only' => false, 'ai_actions.foundation_enabled' => true,
            'ai_actions.execution_enabled' => true, 'ai_communications.reminders_enabled' => true,
            'ai_communications.queue_connection' => 'redis']);
        Bus::fake();
        $company = Company::where('code', 'ZTEX')->firstOrFail();
        $branch = $company->branches()->firstOrFail();
        $year = $company->fiscalYears()->where('status', 'open')->firstOrFail();
        $actor = User::forceCreate(['name' => 'Reminder Administrator', 'email' => uniqid('reminder_').'@example.test',
            'phone' => '9000000061', 'password' => bcrypt('secret'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false]);
        $company->users()->attach($actor->id);
        DB::table('company_user_branches')->insert(['company_id' => $company->id, 'user_id' => $actor->id, 'branch_id' => $branch->id]);
        $this->actingAs($actor);
        $this->context = new CompanyContext($company->id, $branch->id, $year->id);
        $this->principal = new AiPrincipal(actorId: $actor->id, companyId: $company->id, branchId: $branch->id,
            financialYearId: $year->id, permittedToolNames: ['payment_reminder.prepare.v1', 'payment_reminder.send.v1', 'payment_receipt.create.v1']);
        app(AiFeatureGate::class)->setCompanySetting($company->id, true, false);
        $this->customer = Customer::forceCreate(['company_id' => $company->id, 'name' => 'Reviewed Reminder Customer',
            'customer_group_id' => DB::table('customer_groups')->where('company_id', $company->id)->value('id'),
            'email' => 'recipient@example.test', 'phone_number' => '9000000062', 'is_active' => true]);
        app(PartyOpeningBalanceService::class)->post($this->customer, 100, $this->context);
        $this->openItemId = AccountOpenItem::where('company_id', $company->id)->where('party_type', 'customer')
            ->where('party_id', $this->customer->id)->value('id');
        DB::table('document_channels')->updateOrInsert(['company_id' => $company->id, 'channel' => 'email'], [
            'is_active' => true, 'configuration_encrypted' => Crypt::encryptString(json_encode(['from' => 'accounts@example.test'])),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function inputs(): array
    {
        return ['customer_id' => $this->customer->id, 'open_item_id' => $this->openItemId,
            'date' => now()->toDateString()];
    }

    private function proposal(): array
    {
        return app(ActionProposalService::class)->create($this->principal, 'payment_reminder.send.v1', $this->inputs());
    }

    private function queue(array $proposal): int
    {
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        $result = app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
        $this->assertSame('queued', $result['receipt']['status']);
        return (int) $result['receipt']['resource_id'];
    }

    public function test_preparing_a_reminder_draft_never_creates_delivery_and_send_defaults_disabled(): void
    {
        $proposal = app(ActionProposalService::class)->create($this->principal, 'payment_reminder.prepare.v1', $this->inputs());
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
        $this->assertSame(0, DB::table('ai_reminder_outbox')->where('company_id', $this->context->companyId)->count());
        Bus::assertNotDispatched(DeliverPaymentReminder::class);
        config(['ai_communications.reminders_enabled' => false]);
        $this->expectException(AuthorizationException::class);
        $this->proposal();
    }

    public function test_approval_queues_one_encrypted_delivery_and_worker_sends_once_to_the_sealed_recipient(): void
    {
        $proposal = $this->proposal();
        $preview = json_decode($proposal['preview_json'], true);
        $this->assertSame('recipient@example.test', $preview['recipient_email']);
        $this->assertSame('100.0000', $preview['open_amount']);
        $this->assertSame(0, DB::table('ai_reminder_outbox')->where('company_id', $this->context->companyId)->count());
        $id = $this->queue($proposal);
        $row = DB::table('ai_reminder_outbox')->where('company_id', $this->context->companyId)->find($id);
        $this->assertStringNotContainsString('recipient@example.test', $row->payload_encrypted);
        $this->assertSame('recipient@example.test', json_decode(Crypt::decryptString($row->payload_encrypted), true)['recipient_email']);
        $again = app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
        $this->assertSame($id, $again['receipt']['resource_id']);
        $this->artisan('ai:dispatch-reminders', ['--limit' => 1])->assertExitCode(0);
        Bus::assertDispatched(DeliverPaymentReminder::class, fn ($job) => $job->outboxId === $id && $job->companyId === $this->context->companyId);
        Mail::shouldReceive('raw')->once()->withArgs(function (string $body, $callback) use ($preview): bool {
            $message = new Message(new Email());
            $callback($message);
            $this->assertSame('recipient@example.test', $message->getSymfonyMessage()->getTo()[0]->getAddress());
            $this->assertSame('accounts@example.test', $message->getSymfonyMessage()->getFrom()[0]->getAddress());
            return $body === $preview['message'];
        })->andReturn(\Mockery::mock(\Illuminate\Mail\SentMessage::class));
        $worker = new DeliverPaymentReminder($this->context->companyId, $id);
        $worker->handle(app(PaymentReminderService::class));
        $worker->handle(app(PaymentReminderService::class));
        $this->assertDatabaseHas('ai_reminder_outbox', ['id' => $id, 'company_id' => $this->context->companyId, 'status' => 'sent', 'attempts' => 1]);
    }

    public function test_changed_customer_email_invalidates_approval_before_outbox_creation(): void
    {
        $proposal = $this->proposal();
        $this->customer->update(['email' => 'changed@example.test']);
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        try {
            app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
            $this->fail('A changed recipient must invalidate the old approval.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('source_state', $error->errors());
        }
        $this->assertSame(0, DB::table('ai_reminder_outbox')->where('company_id', $this->context->companyId)->count());
    }

    public function test_worker_revalidates_current_balance_and_never_sends_an_outdated_amount(): void
    {
        $id = $this->queue($this->proposal());
        $account = ChartOfAccount::where('company_id', $this->context->companyId)->whereIn('control_type', ['cash', 'bank'])->firstOrFail();
        $receipt = app(ActionProposalService::class)->create($this->principal, 'payment_receipt.create.v1',
            $this->inputs() + ['amount' => '10', 'settlement_account_id' => $account->id]);
        app(ActionApprovalService::class)->approve($this->principal, $receipt['id'], 1);
        app(ApprovedActionExecutor::class)->execute($this->principal, $receipt['id'], 1);
        Mail::shouldReceive('raw')->never();
        app(PaymentReminderService::class)->deliver($this->context->companyId, $id);
        $this->assertDatabaseHas('ai_reminder_outbox', ['id' => $id, 'status' => 'requires_revalidation', 'attempts' => 0]);
    }

    public function test_worker_revalidates_membership_before_delivery(): void
    {
        $id = $this->queue($this->proposal());
        DB::table('company_user_branches')->where('company_id', $this->context->companyId)->where('user_id', $this->principal->actorId)->delete();
        DB::table('company_user')->where('company_id', $this->context->companyId)->where('user_id', $this->principal->actorId)->delete();
        Mail::shouldReceive('raw')->never();
        app(PaymentReminderService::class)->deliver($this->context->companyId, $id);
        $this->assertDatabaseHas('ai_reminder_outbox', ['id' => $id, 'status' => 'requires_revalidation']);
    }

    public function test_uncertain_smtp_failure_is_recorded_and_never_automatically_retried(): void
    {
        $id = $this->queue($this->proposal());
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('SMTP acknowledgement lost.'));
        app(PaymentReminderService::class)->deliver($this->context->companyId, $id);
        app(PaymentReminderService::class)->deliver($this->context->companyId, $id);
        $this->assertDatabaseHas('ai_reminder_outbox', ['id' => $id, 'status' => 'delivery_unknown', 'attempts' => 1]);
        Bus::fake();
        $this->artisan('ai:dispatch-reminders')->assertExitCode(0);
        Bus::assertNotDispatched(DeliverPaymentReminder::class);
    }

    public function test_worker_honors_kill_switch_and_expired_approval(): void
    {
        $id = $this->queue($this->proposal());
        Mail::shouldReceive('raw')->never();
        config(['ai_actions.execution_enabled' => false]);
        app(PaymentReminderService::class)->deliver($this->context->companyId, $id);
        $this->assertDatabaseHas('ai_reminder_outbox', ['id' => $id, 'status' => 'queued', 'attempts' => 0]);
        config(['ai_actions.execution_enabled' => true]);
        $this->travel(2)->days();
        app(PaymentReminderService::class)->deliver($this->context->companyId, $id);
        $this->assertDatabaseHas('ai_reminder_outbox', ['id' => $id, 'status' => 'requires_revalidation', 'attempts' => 0]);
    }

    public function test_suppressed_mail_transport_does_not_claim_a_sent_message(): void
    {
        $id = $this->queue($this->proposal());
        Mail::shouldReceive('raw')->once()->andReturnNull();
        app(PaymentReminderService::class)->deliver($this->context->companyId, $id);
        $this->assertDatabaseHas('ai_reminder_outbox', ['id' => $id, 'status' => 'not_sent', 'failure_category' => 'transport_suppressed']);
    }

    public function test_anonymous_worker_installs_actor_and_restores_request_context_after_delivery(): void
    {
        $id = $this->queue($this->proposal());
        \Illuminate\Support\Facades\Auth::forgetUser();
        request()->setUserResolver(static fn () => null);
        $oldContext = new CompanyContext($this->context->companyId + 1000, $this->context->branchId + 1000, $this->context->financialYearId + 1000);
        request()->attributes->set(CompanyContext::class, $oldContext);
        Mail::shouldReceive('raw')->once()->withArgs(function ($body, $callback): bool {
            $this->assertSame($this->principal->actorId, auth()->id());
            $this->assertSame($this->principal->actorId, request()->user()->id);
            $this->assertSame($this->context->companyId, request()->attributes->get(CompanyContext::class)->companyId);
            return true;
        })->andReturn(\Mockery::mock(\Illuminate\Mail\SentMessage::class));
        app(PaymentReminderService::class)->deliver($this->context->companyId, $id);
        $this->assertDatabaseHas('ai_reminder_outbox', ['id' => $id, 'company_id' => $this->context->companyId, 'status' => 'sent']);
        $this->assertNull(auth()->user());
        $this->assertNull(request()->user());
        $this->assertSame($oldContext, request()->attributes->get(CompanyContext::class));
        request()->attributes->remove(CompanyContext::class);
    }

    public function test_sending_requires_explicit_communication_permission_in_addition_to_accounting_access(): void
    {
        $role = \Spatie\Permission\Models\Role::create(['name' => uniqid('ReminderAccountant'), 'guard_name' => 'web', 'is_active' => true]);
        foreach (['ai.use', 'accounting.voucher.post', 'ai.action.approve'] as $permission) {
            $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        User::whereKey($this->principal->actorId)->update(['role_id' => $role->id]);
        $this->expectException(AuthorizationException::class);
        $this->proposal();
    }

    public function test_reminder_rejects_a_foreign_invoice_and_sync_transport(): void
    {
        try {
            app(ActionProposalService::class)->create($this->principal, 'payment_reminder.send.v1',
                array_replace($this->inputs(), ['open_item_id' => $this->openItemId + 999999]));
            $this->fail('A foreign or missing invoice must be refused.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('open_item_id', $error->errors());
        }
        $proposal = $this->proposal();
        config(['ai_communications.queue_connection' => 'sync']);
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        $this->expectException(ValidationException::class);
        app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
    }
}
