<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Services\ActionSecurity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Declarative plan lifecycle. NO writes to ERP domain tables, NO model calls,
 * NO synthetic proof of physical events and NO implicit approval.
 */
final class BusinessWorkflowPlanner
{
    public function __construct(
        private readonly BusinessKnowledgeRegistry $knowledge,
        private readonly ActionSecurity $security,
        private readonly ActionCapabilityRegistry $capabilities,
    ) {}

    /** Preview topology even when a definition is draft; draft previews can NEVER be started. */
    public function preview(AiPrincipal $principal, string $processId): array
    {
        $this->assertEnabled($principal);
        $definition = $this->knowledge->find($processId);
        if (!$definition || $definition->kind !== 'process') {
            throw ValidationException::withMessages(['process' => 'Unknown business process.']);
        }
        $context = $this->security->context($principal);
        $industry = app(IndustryContextResolver::class)->resolve($context);
        $profileMatches = $definition->appliesToProfile($industry['profile'], $industry['subtype'])
            && $definition->isEffectiveOn()
            && !$definition->isWithdrawnOrDeprecated();
        $steps = [];
        foreach ($definition->data['steps'] as $step) {
            $supported = $profileMatches && $step['kind'] !== 'unsupported_action';
            if ($step['kind'] === 'reviewed_action') {
                $supported = $this->capabilities->has($step['action_key'])
                    && $this->knowledge->forAction($step['action_key'], reviewedOnly: true) !== [];
                if ($supported) {
                    try {
                        $this->security->definition($step['action_key'], $principal);
                    } catch (AuthorizationException|\InvalidArgumentException) {
                        $supported = false;
                    }
                }
            }
            $steps[] = [
                'step_key' => $step['id'], 'kind' => $step['kind'],
                'action_key' => $step['action_key'] ?? null, 'depends_on' => $step['depends_on'],
                'status' => $supported ? 'planned' : 'unsupported',
                'requires_separate_approval' => $step['kind'] === 'reviewed_action',
                'requires_verified_external_event' => $step['kind'] === 'external_event',
            ];
        }
        return [
            'process_id' => $definition->id,
            'knowledge_hash' => $this->knowledge->versionHash(),
            'definition_status' => $definition->status,
            'can_start' => $definition->isReviewed() && !in_array('unsupported', array_column($steps, 'status'), true),
            'writes_executed' => false,
            'steps' => $steps,
            'message' => $definition->isReviewed()
                ? 'Planning preview. No action has been executed.'
                : 'Draft process knowledge only; owner review is required before starting.',
        ];
    }

    /** Persist a reviewed, fully supported plan; immutable per-step task identities remain independent. */
    public function start(AiPrincipal $principal, string $processId, ?string $threadId = null, ?string $taskId = null): array
    {
        $preview = $this->preview($principal, $processId);
        if (!$preview['can_start']) {
            throw ValidationException::withMessages(['process' => 'Process is unreviewed or has unsupported steps.']);
        }
        if ($threadId !== null) {
            $ok = DB::table('ai_thread_scopes')->where([
                'id' => $threadId, 'user_id' => $principal->actorId,
                'company_id' => $principal->companyId, 'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId, 'status' => 'active',
            ])->exists();
            if (!$ok) throw new AuthorizationException('Conversation does not belong to the current context.');
        }
        if ($taskId !== null) {
            $ok = DB::table('ai_workflow_tasks')->where([
                'id' => $taskId, 'actor_id' => $principal->actorId,
                'company_id' => $principal->companyId, 'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ])->where('expires_at', '>', now('UTC'))->exists();
            if (!$ok) throw new AuthorizationException('Task is not in the current context.');
        }
        return DB::transaction(function () use ($principal, $preview, $threadId, $taskId): array {
            $id = (string) Str::uuid();
            $now = now('UTC');
            DB::table('ai_business_workflows')->insert([
                'id' => $id, 'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId, 'financial_year_id' => $principal->financialYearId,
                'actor_id' => $principal->actorId, 'thread_id' => $threadId, 'workflow_task_id' => $taskId,
                'process_id' => $preview['process_id'], 'knowledge_hash' => $preview['knowledge_hash'],
                'status' => 'planned', 'revision' => 1, 'facts_json' => '{}',
                'expires_at' => $now->copy()->addDays(30), 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($preview['steps'] as $step) {
                DB::table('ai_business_workflow_steps')->insert([
                    'id' => (string) Str::uuid(), 'workflow_id' => $id,
                    'step_key' => $step['step_key'], 'kind' => $step['kind'],
                    'action_key' => $step['action_key'],
                    'status' => $step['depends_on'] === []
                        ? $this->initialStatus($step['kind']) : 'blocked',
                    'depends_on_json' => json_encode($step['depends_on'], JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->event($id, $principal, 1, 'created', ['process_id' => $preview['process_id']]);
            return $this->inspect($principal, $id);
        });
    }

    public function inspect(AiPrincipal $principal, string $workflowId): array
    {
        $this->assertEnabled($principal);
        $workflow = $this->owned($principal, $workflowId)->first();
        if (!$workflow) throw new AuthorizationException('Workflow not accessible.');
        $steps = DB::table('ai_business_workflow_steps')->where('workflow_id', $workflowId)
            ->orderBy('created_at')->orderBy('step_key')->get();
        return [
            'workflow_id' => $workflow->id, 'process_id' => $workflow->process_id,
            'revision' => (int) $workflow->revision, 'status' => $workflow->status,
            'knowledge_hash' => $workflow->knowledge_hash,
            'facts' => json_decode($workflow->facts_json ?: '{}', true, flags: JSON_THROW_ON_ERROR),
            'steps' => $steps->map(static fn ($s): array => [
                'step_key' => $s->step_key, 'kind' => $s->kind,
                'action_key' => $s->action_key, 'status' => $s->status,
                'depends_on' => json_decode($s->depends_on_json, true, flags: JSON_THROW_ON_ERROR),
                'proposal_id' => $s->proposal_id,
                'has_verified_evidence' => $s->evidence_hash !== null,
            ])->all(),
            'writes_executed' => false,
        ];
    }

    /** Metadata correction ONLY; not an ERP action or a domain fact assertion. */
    public function correct(AiPrincipal $principal, string $id, int $revision, array $patch): array
    {
        $allowed = ['supplier_query', 'product_query', 'warehouse_query', 'invoice_reference', 'notes'];
        if (count($patch) > 5 || array_diff(array_keys($patch), $allowed) !== []) {
            throw ValidationException::withMessages(['patch' => 'Unsupported planning correction.']);
        }
        foreach ($patch as $field => $value) {
            if (!is_string($value) || mb_strlen($value) > 250) {
                throw ValidationException::withMessages([$field => 'Invalid planning detail.']);
            }
        }
        return $this->mutate($principal, $id, $revision, 'corrected', function ($workflow) use ($patch): array {
            $facts = json_decode($workflow->facts_json ?: '{}', true, flags: JSON_THROW_ON_ERROR);
            return ['facts_json' => json_encode(array_replace($facts, $patch), JSON_THROW_ON_ERROR)];
        }, ['fields_changed' => array_keys($patch)]);
    }

    public function pause(AiPrincipal $principal, string $id, int $revision): array
    {
        return $this->mutate($principal, $id, $revision, 'paused',
            static fn () => ['status' => 'paused']);
    }

    public function cancel(AiPrincipal $principal, string $id, int $revision): array
    {
        return $this->mutate($principal, $id, $revision, 'cancelled',
            static fn () => ['status' => 'cancelled']);
    }

    public function resume(AiPrincipal $principal, string $id, int $revision): array
    {
        return $this->mutate($principal, $id, $revision, 'resumed',
            static fn () => ['status' => 'planned']);
    }

    /** Timeline is read-only; step progression belongs to later verified receipt bridges. */
    private function initialStatus(string $kind): string
    {
        return match ($kind) {
            'read_or_clarify' => 'needs_input',
            'reviewed_action' => 'awaiting_proposal',
            'read_only_validation' => 'needs_validation',
            'external_event' => 'awaiting_real_event',
            default => 'unsupported',
        };
    }

    private function mutate(
        AiPrincipal $principal, string $id, int $revision, string $event,
        \Closure $change, array $audit = [],
    ): array {
        $this->assertEnabled($principal);
        DB::transaction(function () use ($principal, $id, $revision, $event, $change, $audit): void {
            $workflow = $this->owned($principal, $id)->lockForUpdate()->first();
            if (!$workflow) throw new AuthorizationException('Workflow not accessible.');
            if ((int) $workflow->revision !== $revision) {
                throw new ConflictHttpException('Workflow changed. Reload its current revision.');
            }
            if ($workflow->expires_at <= now('UTC')->toDateTimeString()
                || in_array($workflow->status, ['cancelled', 'completed'], true)) {
                throw ValidationException::withMessages(['workflow' => 'Workflow can no longer be edited.']);
            }
            if ($event === 'resumed' && $workflow->status !== 'paused') {
                throw ValidationException::withMessages(['workflow' => 'Only paused workflows can be resumed.']);
            }
            $updates = $change($workflow);
            $affected = $this->owned($principal, $id)->where('revision', $revision)
                ->update($updates + ['revision' => $revision + 1, 'updated_at' => now('UTC')]);
            if ($affected !== 1) throw new ConflictHttpException('Workflow revision conflict.');
            $this->event($id, $principal, $revision + 1, $event, $audit);
        }, 3);
        return $this->inspect($principal, $id);
    }

    private function event(string $id, AiPrincipal $principal, int $revision, string $name, array $metadata = []): void
    {
        DB::table('ai_business_workflow_events')->insert([
            'id' => (string) Str::uuid(), 'workflow_id' => $id,
            'actor_id' => $principal->actorId, 'revision' => $revision, 'event_type' => $name,
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now('UTC'),
        ]);
    }

    private function owned(AiPrincipal $principal, string $id)
    {
        return DB::table('ai_business_workflows')->where([
            'id' => $id, 'company_id' => $principal->companyId, 'branch_id' => $principal->branchId,
            'financial_year_id' => $principal->financialYearId, 'actor_id' => $principal->actorId,
        ]);
    }

    private function assertEnabled(AiPrincipal $principal): void
    {
        if (!config('ai_business_knowledge.enabled', false) || !config('ai_business_knowledge.planner_enabled', false)) {
            throw new AuthorizationException('Business workflow planning is disabled.');
        }
        $this->security->assertEnabled($principal);
    }
}
