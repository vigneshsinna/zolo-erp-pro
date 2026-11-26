<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates immutable approval audit for an exact proposal VERSION. Approval
 * alone never executes an ERP operation.
 */
final class ActionApprovalService
{
    public function __construct(
        private readonly ActionSecurity $security,
        private readonly ActionApprovalSignature $signatures,
    ) {}

    public function approve(AiPrincipal $approver, string $proposalId, int $expectedVersion): array
    {
        $this->security->assertEnabled($approver);
        $this->security->context($approver);
        app(\App\Services\Commercial\CommercialPermission::class)->assert('ai.action.approve', $this->security->context($approver), $approver->actorId);
        return DB::transaction(function () use ($approver, $proposalId, $expectedVersion): array {
            $proposal = DB::table('ai_action_proposals')
                ->where('id', $proposalId)
                ->where('company_id', $approver->companyId)
                ->where('branch_id', $approver->branchId)
                ->where('financial_year_id', $approver->financialYearId)
                ->whereNotNull('payload_hash')
                ->lockForUpdate()->first();

            if (!$proposal) {
                throw new AuthorizationException('Action proposal not accessible.');
            }
            $this->security->definition($proposal->action_key, $approver);
            $isExpired = !$proposal->expires_at || \Illuminate\Support\Carbon::parse($proposal->expires_at, 'UTC')->isPast();
            if ($proposal->status !== 'pending' || (int) $proposal->version !== $expectedVersion || $isExpired) {
                throw ValidationException::withMessages(['proposal' => 'The proposal is stale or already decided.']);
            }

            $payload = json_decode($proposal->payload_json, true, flags: JSON_THROW_ON_ERROR);
            if (!hash_equals($proposal->payload_hash, CanonicalActionPayload::hash($payload))) {
                throw ValidationException::withMessages(['proposal' => 'Proposal contents do not match the sealed version.']);
            }

            $timestamp = now('UTC')->format('Y-m-d H:i:s');
            $envelope = self::envelope($proposal, $approver->actorId, $timestamp);
            $signature = $this->signatures->sign($envelope);
            DB::table('ai_action_approvals')->insert([
                'id' => (string) Str::uuid(),
                'proposal_id' => $proposalId,
                'approver_id' => $approver->actorId,
                'decision' => 'approved',
                'version' => $expectedVersion,
                'approval_signature' => $signature,
                'notes' => null,
                'created_at' => $timestamp,
            ]);

            DB::table('ai_action_proposals')->where('id', $proposalId)->update([
                'status' => 'approved',
                'updated_at' => now(),
            ]);

            return ['proposal_id' => $proposalId, 'version' => $expectedVersion, 'status' => 'approved'];
        });
    }

    public function assertApproved(object $proposal): object
    {
        $approval = DB::table('ai_action_approvals')
            ->where('proposal_id', $proposal->id)
            ->where('version', $proposal->version)
            ->where('decision', 'approved')
            ->whereNotNull('approval_signature')
            ->orderByDesc('created_at')
            ->first();
        if (!$approval) {
            throw new AuthorizationException('Valid human approval is required.');
        }

        $timestamp = \Carbon\Carbon::parse($approval->created_at, 'UTC')->format('Y-m-d H:i:s');
        if (!$this->signatures->matches(self::envelope($proposal, (int) $approval->approver_id, $timestamp), $approval->approval_signature)) {
            throw new AuthorizationException('Human approval signature does not match proposal.');
        }

        return $approval;
    }

    public static function envelope(object $proposal, int $approverId, string $timestamp): array
    {
        return [
            'type' => 'zoloerp.action-approval.v1',
            'proposal_id' => (string) $proposal->id,
            'action_key' => (string) $proposal->action_key,
            'version' => (int) $proposal->version,
            'payload_hash' => (string) $proposal->payload_hash,
            'source_state_hash' => (string) $proposal->source_state_hash,
            'approver_id' => $approverId,
            'actor_id' => (int) $proposal->actor_id,
            'company_id' => $proposal->company_id === null ? null : (int) $proposal->company_id,
            'branch_id' => $proposal->branch_id === null ? null : (int) $proposal->branch_id,
            'financial_year_id' => $proposal->financial_year_id === null ? null : (int) $proposal->financial_year_id,
            'created_at_utc' => $timestamp,
        ];
    }
}
