<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Services\ActionSecurity;
use App\Ai\Services\TaskCorrectionService;
use App\Ai\Services\WorkflowTaskStateService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ConversationTurnRouter
{
    public function __construct(
        private readonly ActiveWorkflowTaskResolver $resolver,
        private readonly WorkflowTaskStateService $state,
        private readonly TaskCorrectionService $corrections,
    ) {}

    public function startsPurchaseOrder(string $message): bool
    {
        return (bool) preg_match('/\b(?:create|prepare|raise|make|start)\s+(?:a\s+|an\s+|new\s+)?(?:purchase\s+order|po)\b/i', $message);
    }

    // The caller holds the owned thread row lock until its messages are stored.
    public function route(AiPrincipal $principal, string $threadId, array $input, ?array $interpretation = null): ?array
    {
        $message = trim($input['message']);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $receipt = DB::table('ai_workflow_task_events')->whereIn('task_id', $this->resolver->tasks($principal, $threadId)->select('id'))
            ->where('event_type', 'conversation_turn')->where('metadata_json->client_turn_id', $input['client_turn_id'] ?? '')->first();
        if ($receipt) {
            $receiptTask = $this->state->getTask($principal, $receipt->task_id);
            app(ActionSecurity::class)->definition($receiptTask['action_key'], $principal);
            if ($receiptTask['expires_at'] <= now('UTC')->toDateTimeString()) {
                throw ValidationException::withMessages(['task' => 'The draft expired. Start a new draft.']);
            }
            $metadata = json_decode($receipt->metadata_json, true, flags: JSON_THROW_ON_ERROR);
            if (!hash_equals($metadata['request_hash'], $hash)) {
                throw new ConflictHttpException('The conversation turn UUID was already used for different input.');
            }
            return $metadata['result'] + ['replayed' => true];
        }
        $tasks = $this->resolver->resolve($principal, $threadId, $input['active_task_id'] ?? null);
        if (!$this->startsPurchaseOrder($message) && $tasks === []) {
            return null;
        }
        if (empty($input['client_turn_id'])) {
            throw ValidationException::withMessages(['client_turn_id' => 'A conversation turn UUID is required.']);
        }
        if (count($tasks) > 1) {
            return ['status' => 'needs_input', 'message' => 'Which draft should I continue?',
                'tasks' => array_map(fn ($task) => ['task_id' => $task['id'], 'action_key' => $task['action_key'], 'task_revision' => (int) $task['revision']], $tasks)];
        }
        if ($tasks === []) {
            app(ActionSecurity::class)->definition('purchase_order.create.v1', $principal);
            $task = $this->state->createTask(new WorkflowTaskContext(
                principal: $principal,
                context: new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId),
                threadId: $threadId,
            ), 'purchase_order.create.v1');
        } else {
            $task = $tasks[0];
            app(ActionSecurity::class)->definition($task['action_key'], $principal);
        }
        if (preg_match('/^(?:who|what|how|show|list|report)\b/i', $message)) {
            return null;
        }
        if (preg_match('/\b(?:sales order|sales invoice|purchase bill|journal|payment)\b/i', $message)) {
            return $this->summary($task, 'The purchase draft is preserved. Select or start a separate operation explicitly.');
        }
        if (preg_match('/^cancel\b/i', $message)) {
            $this->assertRevision($task, $input);
            $this->state->updateTaskState($principal, $task['id'], (int) $task['revision'],
                json_decode($task['slots_json'], true), status: 'cancelled');
            $result = $this->summary($this->state->getTask($principal, $task['id']), 'Draft cancelled. No ERP document was changed.');
            $result['status'] = 'cancelled';
        } else {
            $interpreter = app(ConversationalSlotInterpreter::class);
            $parsed = $interpreter->interpret($task, $message);
            if ($interpretation !== null) {
                if ($interpretation['task_id'] !== $task['id'] || $interpretation['revision'] !== (int) $task['revision']) {
                    throw new ConflictHttpException('Task changed while interpreting the reply. Reload the current draft.');
                }
                $parsed = $interpretation['parsed'];
            }
            if ($tasks !== [] && $parsed['corrections'] !== []) {
                $this->assertRevision($task, $input);
            }
            if ($tasks === [] || $parsed['corrections'] !== [] || preg_match('/^(?:continue|resume|preview)$/i', $message)) {
                $applied = $this->corrections->applyCorrections($principal, $task['id'], (int) $task['revision'], $parsed['corrections']);
                $task = $applied['task'];
                $result = $applied['clarification'] ?? $this->corrections->prepareProposal($principal, $task, $applied['evaluation']);
            } else {
                $result = $this->summary($task, 'Confirm the intended values or use the existing detail controls.');
            }
            $result['warnings'] = $parsed['warnings'];
        }
        DB::table('ai_workflow_task_events')->insert([
            'id' => (string) Str::uuid(), 'task_id' => $task['id'], 'actor_id' => $principal->actorId,
            'event_type' => 'conversation_turn', 'revision' => $result['task_revision'],
            'metadata_json' => json_encode(['client_turn_id' => $input['client_turn_id'], 'request_hash' => $hash, 'result' => $result], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        return $result;
    }

    // Provider billing must commit outside the draft transaction, including rejected or stale output.
    public function interpretTurn(AiPrincipal $principal, string $threadId, array $input): ?array
    {
        if (DB::table('ai_workflow_task_events')->whereIn('task_id', $this->resolver->tasks($principal, $threadId)->select('id'))
            ->where('event_type', 'conversation_turn')->where('metadata_json->client_turn_id', $input['client_turn_id'] ?? '')->exists()) {
            return null;
        }
        $tasks = $this->resolver->resolve($principal, $threadId, $input['active_task_id'] ?? null);
        if (count($tasks) !== 1 || preg_match('/^(?:cancel|continue|resume|preview|who|what|how|show|list|report)\b/i', trim($input['message']))
            || preg_match('/\b(?:sales order|sales invoice|purchase bill|journal|payment)\b/i', $input['message'])) {
            return null;
        }
        $task = $tasks[0];
        app(ActionSecurity::class)->definition($task['action_key'], $principal);
        $interpreter = app(ConversationalSlotInterpreter::class);
        $parsed = $interpreter->interpret($task, $input['message']);
        if ($parsed['turn_intent'] !== 'unknown') {
            return null;
        }
        $this->assertRevision($task, $input);
        $gateway = app(\App\Ai\Gateway\ApprovedModelGateway::class);
        $model = $gateway->resolveApprovedModel();
        $gateway->admitRequest($principal, now('UTC')->toDateString(), $threadId);
        $slots = json_decode($task['slots_json'], true, flags: JSON_THROW_ON_ERROR);
        $response = (new ConversationParseAgent($principal))->prompt(json_encode(['utterance' => $input['message'],
            'lines' => array_map(fn ($line) => array_intersect_key($line, array_flip(['client_line_id', 'product_query', 'qty'])), $slots['items'] ?? [])], JSON_THROW_ON_ERROR),
            provider: $model['provider'], model: $model['model'], timeout: 20);
        $raw = $response->toArray();
        foreach ($raw['corrections'] ?? [] as $index => $correction) {
            if (($correction['operation'] ?? '') === 'add_line' && is_string($correction['value'])) {
                $raw['corrections'][$index]['value'] = json_decode($correction['value'], true, 8, JSON_THROW_ON_ERROR);
            }
        }
        return ['task_id' => $task['id'], 'revision' => (int) $task['revision'], 'parsed' => $interpreter->validateModelResult($task, $raw)];
    }

    private function assertRevision(array $task, array $input): void
    {
        if (($input['expected_task_revision'] ?? null) !== (int) $task['revision']) {
            throw new ConflictHttpException('Task revision changed. Reload the current draft before applying your reply.');
        }
    }

    private function summary(array $task, string $message): array
    {
        return ['status' => 'needs_input', 'task_id' => $task['id'], 'task_revision' => (int) $task['revision'],
            'action_key' => $task['action_key'], 'message' => $message,
            'required_inputs' => json_decode($task['issues_json'] ?: '[]', true), 'can_prepare_proposal' => false];
    }
}
