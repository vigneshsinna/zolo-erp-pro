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
        return app(VoiceIntentResolver::class)->resolve($message) === 'purchase_order.create.v1';
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
        $directoryTypes = app(ConversationDirectoryIntent::class)->types($message);
        // Read-only questions must not be sent through the correction model.
        if ($directoryTypes === [] && app(ConversationDirectoryIntent::class)->isReadOnlyQuestion($message)) {
            return null;
        }
        if (preg_match('/\b(?:sales order|sales invoice|purchase bill|journal|payment)\b/i', $message)) {
            return $this->summary($task, 'The purchase draft is preserved. Select or start a separate operation explicitly.');
        }
        if ($directoryTypes !== []) {
            $result = $this->withDirectories(
                $principal, $task,
                $this->summary($task, 'Here are the eligible records in your company. Choose one or tell me the product and quantity to add.'),
                $directoryTypes, $message,
            );
        } elseif (preg_match('/^cancel\b/i', $message)) {
            $this->assertRevision($task, $input);
            if ($task['linked_proposal_id']) {
                $service = app(\App\Ai\Services\ActionProposalService::class);
                $proposal = $service->getOwned($principal, $task['linked_proposal_id']);
                $service->reject($principal, $proposal['id'], (int) $proposal['version']);
            }
            $this->state->updateTaskState($principal, $task['id'], (int) $task['revision'],
                json_decode($task['slots_json'], true), status: 'cancelled');
            $result = $this->summary($this->state->getTask($principal, $task['id']), 'Draft cancelled. No ERP document was changed.');
            $result['status'] = 'cancelled';
        } elseif (app(ConversationMasterContinuation::class)->handles($task, $message)) {
            $this->assertRevision($task, $input);
            $result = app(ConversationMasterContinuation::class)->turn($principal, $task, $message);
        } else {
            $interpreter = app(ConversationalSlotInterpreter::class);
            $selection = null;
            if (app(ConversationChoices::class)->isSelection($message, $input)) {
                $selection = app(ConversationChoices::class)->select($principal, $task, $input);
            }
            $interpretTask = $task;
            if ($selection !== null && $selection['remaining_message'] !== '') {
                // When selecting a product into an empty draft, the remaining spoken
                // quantity/rate belongs to that newly selected line, not a different line.
                $previewSlots = json_decode($task['slots_json'], true, flags: JSON_THROW_ON_ERROR);
                foreach ($selection['corrections'] as $selected) {
                    if (preg_match('/^items\[([a-zA-Z0-9_-]+)\]\.product_id$/D', $selected['field'], $lineMatch)) {
                        if (!collect($previewSlots['items'] ?? [])->contains('client_line_id', $lineMatch[1])) {
                            $previewSlots['items'][] = ['client_line_id' => $lineMatch[1]];
                        }
                    }
                }
                $interpretTask['slots_json'] = json_encode($previewSlots, JSON_THROW_ON_ERROR);
            }
            $parsed = $interpreter->interpret($interpretTask, $selection['remaining_message'] ?? $message);
            if ($selection !== null) {
                $parsed['corrections'] = [...$selection['corrections'], ...$parsed['corrections']];
            }
            if ($interpretation !== null) {
                if ($interpretation['task_id'] !== $task['id'] || $interpretation['revision'] !== (int) $task['revision']) {
                    throw new ConflictHttpException('Task changed while interpreting the reply. Reload the current draft.');
                }
                $parsed = $interpretation['parsed'];
            }
            if ($tasks !== [] && $parsed['corrections'] !== []) {
                $this->assertRevision($task, $input);
            }
            if (isset($input['voice_source'])) {
                foreach ($parsed['corrections'] as &$correction) {
                    $correction['confirmation'] = 'user_voice';
                }
                unset($correction);
            }
            if ($tasks === [] || $parsed['corrections'] !== [] || preg_match('/^(?:continue|resume|preview)$/i', $message)) {
                $applied = $this->corrections->applyCorrections($principal, $task['id'], (int) $task['revision'], $parsed['corrections']);
                $task = $applied['task'];
                $result = $applied['clarification'] ?? $this->corrections->prepareProposal($principal, $task, $applied['evaluation']);
            } else {
                $result = $this->summary($task, 'Confirm the intended values or use the existing detail controls.');
            }
            $result['warnings'] = array_values(array_unique([...$parsed['warnings'], ...($input['voice_source']['warnings'] ?? [])]));
        }
        $task = $this->state->getTask($principal, $task['id']);
        if (($result['status'] ?? '') === 'needs_input' && $directoryTypes === []) {
            $missing = array_column(array_filter($result['required_inputs'] ?? [], fn ($issue) => ($issue['status'] ?? '') === 'missing'), 'field');
            $missingQuantities = array_values(array_filter($missing, fn ($field) => str_ends_with((string) $field, '.qty')));
            if ($missingQuantities !== []) {
                $result['message'] = count($missingQuantities) === 1
                    ? 'How many units of the selected product? You can reply “100 pairs at 500” or correct the rate separately.'
                    : 'Please provide the quantities for the unresolved lines. Use Detail controls to identify a specific line.';
                if (in_array('supplier_id', $missing, true)) {
                    $result['message'] .= ' Choose a supplier from the available options as well.';
                }
            } elseif (in_array('supplier_id', $missing, true) && in_array('items', $missing, true)) {
                $result['message'] = 'Choose a supplier and product, then tell me the quantity. You can also search or request a new record.';
            } elseif (in_array('payment_terms_type', $missing, true) && !in_array('supplier_id', $missing, true)) {
                $result['message'] = 'Cash or Credit terms? For Credit, how many days? No payment is posted by an order.';
            }
            // On a new PO or after a record selection, offer the next available
            // choices. Browsing itself remains read-only; only selection changes slots.
            if ($tasks === [] || app(ConversationChoices::class)->isSelection($message, $input)) {
                $next = [];
                if (in_array('supplier_id', $missing, true)) {
                    $next[] = 'supplier';
                }
                if (in_array('warehouse_id', $missing, true)) {
                    $next[] = 'warehouse';
                }
                if (in_array('items', $missing, true) || collect($result['required_inputs'] ?? [])
                    ->contains(fn ($issue) => str_ends_with((string) ($issue['field'] ?? ''), '.product_id')
                        && ($issue['status'] ?? '') === 'missing')) {
                    $next[] = 'product';
                }
                if ($next !== []) {
                    $result = $this->withDirectories($principal, $task, $result, $next, '', true, 6);
                }
            }
        }
        $result = app(ConversationChoices::class)->publish($principal, $task, $result);
        DB::table('ai_workflow_task_events')->insert([
            'id' => (string) Str::uuid(), 'task_id' => $task['id'], 'actor_id' => $principal->actorId,
            'event_type' => 'conversation_turn', 'revision' => $result['task_revision'],
            'metadata_json' => json_encode(['client_turn_id' => $input['client_turn_id'], 'request_hash' => $hash,
                'source' => isset($input['voice_source']) ? array_diff_key($input['voice_source'], ['warnings' => true]) : ['kind' => 'text'], 'result' => $result], JSON_THROW_ON_ERROR),
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
        if (count($tasks) !== 1 || app(ConversationDirectoryIntent::class)->isReadOnlyQuestion($input['message'])
            || preg_match('/^(?:cancel|continue|resume|preview|who|what|how|show|list|report)\b/i', trim($input['message']))
            || preg_match('/\b(?:sales order|sales invoice|purchase bill|journal|payment)\b/i', $input['message'])
            || preg_match('/^(?:find|search)\s+(?:suppliers?|warehouses?|products?|units?)\b/i', $input['message'])
            || app(ConversationChoices::class)->isSelection($input['message'], $input)) {
            return null;
        }
        $task = $tasks[0];
        if (app(ConversationMasterContinuation::class)->handles($task, $input['message'])) {
            return null;
        }
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


    /**
     * Build scoped directory cards and server-versioned options without writing
     * supplier, product or warehouse IDs into the draft.
     */
    private function withDirectories(
        AiPrincipal $principal,
        array $task,
        array $result,
        array $types,
        string $message = '',
        bool $append = false,
        int $limit = 20,
    ): array {
        $issues = $append ? ($result['required_inputs'] ?? []) : [];
        $directories = [];
        foreach ($types as $type) {
            $query = $message === '' ? '' : app(ConversationDirectoryIntent::class)->query($message, $type, count($types));
            $issue = app(ConversationChoices::class)->directory($principal, $task, $type, $query);
            $issue['candidates'] = array_slice($issue['candidates'], 0, $limit);
            $hasMore = (bool) $issue['has_more'] || (int) $issue['total'] > count($issue['candidates']);
            $directories[] = [
                'entity_type' => $type,
                'items' => array_map(static fn (array $candidate): array => [
                    'name' => (string) ($candidate['name'] ?? $candidate['label'] ?? ''),
                    'code' => (string) ($candidate['code'] ?? ''),
                ], $issue['candidates']),
                'total' => (int) $issue['total'],
                'has_more' => $hasMore,
                'can_create' => (bool) $issue['can_create'],
                'create_disabled_reason' => $issue['create_disabled_reason'],
            ];
            if (in_array($type, ['supplier', 'warehouse'], true)
                || str_starts_with($issue['field'], 'items[')) {
                $issues[] = $issue;
            }
        }
        $result['required_inputs'] = $issues;
        $result['directories'] = $directories;
        if (array_sum(array_map(static fn (array $directory): int => count($directory['items']), $directories)) === 0) {
            $result['message'] = 'No eligible matches. Search another name or request approved creation of a new record.';
        }
        return $result;
    }

    private function summary(array $task, string $message): array
    {
        return ['status' => 'needs_input', 'task_id' => $task['id'], 'task_revision' => (int) $task['revision'],
            'action_key' => $task['action_key'], 'message' => $message,
            'required_inputs' => json_decode($task['issues_json'] ?: '[]', true), 'can_prepare_proposal' => false];
    }
}
