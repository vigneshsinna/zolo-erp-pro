<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Ai\Agents\DeepSeekAgent;
use App\Ai\Agents\ErpCopilot;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Ai\Services\VerifiedSummaryBuilder;
use App\Http\Controllers\Controller;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\AgentResponse;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class AiChatController extends Controller
{
    public function __construct(
        protected CompanyContextResolver $contextResolver,
        protected AiPrincipalFactory $principalFactory,
        protected AiFeatureGate $featureGate,
        protected ApprovedModelGateway $modelGateway,
    ) {
    }

    public function settings(Request $request): JsonResponse
    {
        $principal = $this->principal($request);

        return response()->json([
            'enabled' => $this->featureGate->isEnabledForCompany($principal->companyId),
            'read_only' => $this->featureGate->isReadOnly(),
            'allowed_tools' => $principal->permittedToolNames,
            ...$this->modelGateway->resolveApprovedModel(),
            'scope' => $this->scope($principal),
        ]);
    }

    public function monitoring(Request $request): JsonResponse
    {
        $principal = $this->principal($request);
        if (!$this->contextResolver->canManageFinancialYears($principal->actorId, $principal->companyId)) {
            return $this->error('AI_FORBIDDEN', 'Administrator privileges are required to view AI telemetry and monitoring.', 403);
        }

        $quotaDate = now('UTC')->toDateString();
        $model = $this->modelGateway->resolveApprovedModel();
        $rpmUsage = $this->modelGateway->getProviderRpmUsage($model['provider']);

        // Today's aggregated usage for active company
        $counters = DB::table('ai_usage_counters')
            ->where('company_id', $principal->companyId)
            ->where('date', $quotaDate)
            ->selectRaw('SUM(request_count) as user_requests, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(reserved_tokens) as reserved_tokens')
            ->first();

        $todayModelCalls = DB::table('ai_model_invocations')
            ->where('company_id', $principal->companyId)
            ->whereDate('created_at', $quotaDate)
            ->count();
        $todayHttpAttempts = (int) DB::table('ai_model_invocations')
            ->where('company_id', $principal->companyId)->whereDate('created_at', $quotaDate)->sum('http_attempts');
        $todayRetries = (int) DB::table('ai_model_invocations')
            ->where('company_id', $principal->companyId)->whereDate('created_at', $quotaDate)
            ->sum(DB::raw('GREATEST(COALESCE(http_attempts, 0) - 1, 0)'));

        $todayToolCalls = DB::table('ai_tool_invocations')
            ->join('ai_runs', 'ai_runs.id', '=', 'ai_tool_invocations.run_id')
            ->where('ai_runs.company_id', $principal->companyId)
            ->whereDate('ai_tool_invocations.created_at', $quotaDate)
            ->count();

        $companySetting = DB::table('ai_feature_settings')
            ->where('company_id', $principal->companyId)
            ->first();

        $inputTokens = (int) ($counters->input_tokens ?? 0);
        $outputTokens = (int) ($counters->output_tokens ?? 0);
        $totalTokens = $inputTokens + $outputTokens;
        $dailyTokenLimit = (int) ($companySetting->daily_token_limit ?? config('erp_ai.daily_company_tokens', 100000));

        // Latest runs with step and tool counts
        $recentRuns = DB::table('ai_runs')
            ->where('company_id', $principal->companyId)
            ->latest('started_at')
            ->limit(10)
            ->get(['id as run_id', 'model_provider', 'model_key', 'status', 'input_tokens', 'output_tokens', 'started_at', 'completed_at'])
            ->map(function (stdClass $run): array {
                $modelCalls = DB::table('ai_model_invocations')->where('run_id', $run->run_id)->count();
                $toolCalls = DB::table('ai_tool_invocations')->where('run_id', $run->run_id)->count();

                return [
                    'run_id' => $run->run_id,
                    'provider' => $run->model_provider,
                    'model' => $run->model_key,
                    'status' => $run->status,
                    'input_tokens' => (int) $run->input_tokens,
                    'output_tokens' => (int) $run->output_tokens,
                    'total_tokens' => (int) ($run->input_tokens + $run->output_tokens),
                    'model_calls' => $modelCalls,
                    'erp_tool_calls' => $toolCalls,
                    'started_at' => $run->started_at,
                    'completed_at' => $run->completed_at,
                ];
            });

        return response()->json([
            'status' => 'ok',
            'provider' => $model['provider'],
            'model' => $model['model'],
            'rpm' => $rpmUsage,
            'today' => [
                'date' => $quotaDate,
                'user_requests' => (int) ($counters->user_requests ?? 0),
                'model_invocations' => $todayModelCalls,
                'provider_http_attempts' => $todayHttpAttempts,
                'provider_retries' => $todayRetries,
                'erp_tool_calls' => $todayToolCalls,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
                'daily_token_limit' => $dailyTokenLimit,
                'tokens_remaining' => max(0, $dailyTokenLimit - $totalTokens),
            ],
            'recent_runs' => $recentRuns,
            'scope' => $this->scope($principal),
        ]);
    }

    public function threads(Request $request): JsonResponse
    {
        $principal = $this->principal($request);
        if ($denial = $this->accessDenial($principal)) {
            return $denial;
        }

        return response()->json([
            'threads' => $this->ownedThreads($principal)->latest('updated_at')->limit(50)
                ->get(['id', 'title', 'created_at', 'updated_at']),
            'scope' => $this->scope($principal),
        ]);
    }

    public function proposals(Request $request): JsonResponse
    {
        $principal = $this->principal($request);
        if ($denial = $this->accessDenial($principal)) {
            return $denial;
        }

        if (!config('ai_actions.foundation_enabled', false)) {
            return $this->error('AI_ACTIONS_DISABLED', 'Copilot action proposals are unavailable.', 403);
        }

        $query = DB::table('ai_action_proposals')
            ->where('company_id', $principal->companyId)
            ->where('branch_id', $principal->branchId)
            ->where('financial_year_id', $principal->financialYearId);

        // Strict actor filtering: ordinary users only see their own proposals.
        // Reviewers with ai.action.approve can inspect company-wide proposals only when requesting all=1.
        $canReviewAll = app(\App\Services\Commercial\CommercialPermission::class)->allows(
            'ai.action.approve',
            new \App\Services\Platform\CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId),
            $principal->actorId
        );

        if ($request->boolean('all', false)) {
            if (!$canReviewAll) {
                return response()->json(['error_code' => 'UNAUTHORIZED', 'error_message' => 'Unauthorized to view all proposals.'], 403);
            }
        } else {
            $query->where('actor_id', $principal->actorId);
        }

        $rows = $query->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'proposals' => $rows->map(function ($row) {
                return [
                    'id' => $row->id,
                    'action_key' => $row->action_key,
                    'action_title' => $row->action_title,
                    'risk_tier' => $row->risk_tier,
                    'version' => (int) $row->version,
                    'status' => $row->status,
                    'payload_hash' => $row->payload_hash,
                    'source_state_hash' => $row->source_state_hash,
                    'preview' => json_decode((string) $row->preview_json, true) ?? [],
                    'expires_at' => $row->expires_at,
                    'created_at' => $row->created_at,
                ];
            }),
        ]);
    }

    public function propose(Request $request): JsonResponse
    {
        $principal = $this->principal($request);
        if ($denial = $this->accessDenial($principal)) {
            return $denial;
        }

        if (!config('ai_actions.foundation_enabled', false)) {
            return $this->error('AI_ACTIONS_DISABLED', 'Copilot action proposals are unavailable.', 403);
        }

        $actionKey = (string) $request->input('action_key');
        $slots = (array) $request->input('slots', []);
        $threadId = $request->input('thread_id') ? (string) $request->input('thread_id') : null;

        $proposal = app(\App\Ai\Services\ActionProposalService::class)->create($principal, $actionKey, $slots, $threadId);

        return response()->json([
            'status' => 'pending',
            'proposal' => [
                'id' => $proposal['id'],
                'action_key' => $proposal['action_key'],
                'version' => (int) $proposal['version'],
                'status' => $proposal['status'] ?? 'pending',
                'payload_hash' => $proposal['payload_hash'],
                'source_state_hash' => $proposal['source_state_hash'],
                'preview' => json_decode((string) $proposal['preview_json'], true) ?? [],
                'expires_at' => $proposal['expires_at'],
            ],
        ], 201);
    }

    public function changeRequest(Request $request, string $id): JsonResponse
    {
        $principal = $this->principal($request);
        if ($denial = $this->accessDenial($principal)) {
            return $denial;
        }

        if (!config('ai_actions.foundation_enabled', false)) {
            return $this->error('AI_ACTIONS_DISABLED', 'Copilot action proposals are unavailable.', 403);
        }

        $changes = (array) $request->input('changes', []);
        $expectedVersion = (int) $request->input('expected_version', 1);

        $proposal = app(\App\Ai\Services\ActionProposalService::class)->applyChangeRequest(
            $principal,
            $id,
            $changes,
            $expectedVersion
        );

        return response()->json([
            'status' => 'pending',
            'proposal' => [
                'id' => $proposal['id'],
                'version' => (int) $proposal['version'],
                'status' => $proposal['status'] ?? 'pending',
                'payload_hash' => $proposal['payload_hash'],
                'source_state_hash' => $proposal['source_state_hash'],
                'preview' => json_decode((string) $proposal['preview_json'], true) ?? [],
                'expires_at' => $proposal['expires_at'],
            ],
        ]);
    }

    public function approveProposal(Request $request, string $id): JsonResponse
    {
        $principal = $this->principal($request);
        if ($denial = $this->accessDenial($principal)) {
            return $denial;
        }

        if (!config('ai_actions.foundation_enabled', false)) {
            return $this->error('AI_ACTIONS_DISABLED', 'Copilot action proposals are unavailable.', 403);
        }

        $expectedVersion = (int) $request->input('expected_version', 1);

        if (config('ai_actions.execution_enabled', false)) {
            $existingExecution = DB::table('ai_action_executions')
                ->where('company_id', $principal->companyId)
                ->where('proposal_id', $id)
                ->where('version', $expectedVersion)
                ->first();

            if ($existingExecution && $existingExecution->status === 'completed') {
                $execution = app(\App\Ai\Services\ApprovedActionExecutor::class)->execute($principal, $id, $expectedVersion);
                return response()->json([
                    'status' => 'completed',
                    'proposal_id' => $id,
                    'version' => $expectedVersion,
                    'execution' => $execution,
                ]);
            }
        }

        $approval = app(\App\Ai\Services\ActionApprovalService::class)->approve($principal, $id, $expectedVersion);

        if (config('ai_actions.execution_enabled', false)) {
            $execution = app(\App\Ai\Services\ApprovedActionExecutor::class)->execute($principal, $id, $expectedVersion);
            return response()->json([
                'status' => 'completed',
                'proposal_id' => $id,
                'version' => $expectedVersion,
                'execution' => $execution,
            ]);
        }

        return response()->json([
            'status' => 'approved',
            'proposal_id' => $id,
            'version' => $expectedVersion,
            'approval' => $approval,
        ]);
    }

    public function rejectProposal(Request $request, string $id): JsonResponse
    {
        $principal = $this->principal($request);
        if ($denial = $this->accessDenial($principal)) {
            return $denial;
        }

        if (!config('ai_actions.foundation_enabled', false)) {
            return $this->error('AI_ACTIONS_DISABLED', 'Copilot action proposals are unavailable.', 403);
        }

        $expectedVersion = (int) $request->input('expected_version', 1);

        app(\App\Ai\Services\ActionProposalService::class)->reject($principal, $id, $expectedVersion);

        return response()->json([
            'status' => 'cancelled',
            'proposal_id' => $id,
        ]);
    }

    public function createThread(Request $request): JsonResponse
    {
        $principal = $this->principal($request);
        if ($denial = $this->accessDenial($principal)) {
            return $denial;
        }

        $validated = $request->validate(['title' => 'nullable|string|max:100']);
        $id = (string) Str::uuid();
        $title = $validated['title'] ?? 'New AI Session';
        $this->insertThread($principal, $id, $title);

        return response()->json(['id' => $id, 'title' => $title, 'created_at' => now()->toIso8601String()], 201);
    }

    public function showThread(string $id, Request $request): JsonResponse
    {
        $principal = $this->principal($request);
        if ($denial = $this->accessDenial($principal)) {
            return $denial;
        }

        $thread = $this->ownedThreads($principal)->where('id', $id)->first();
        if (!$thread) {
            return $this->threadNotFound();
        }

        $messages = $this->messages($thread->id)
            ->get(['id', 'role', 'content', 'created_at', 'meta'])
            ->map(function (stdClass $message) use ($principal): array {
                $metadata = json_decode($message->meta, true) ?? [];
                unset($message->meta);

                if (!$this->canReplay($principal, $metadata)) {
                    $message->content = 'This result is unavailable with your current ERP access.';
                    $metadata = [];
                }

                return [
                    ...(array) $message,
                    'facts' => $metadata['facts'] ?? [],
                    'sources' => $metadata['sources'] ?? [],
                    'warnings' => $metadata['warnings'] ?? [],
                ];
            });

        return response()->json(['thread' => $thread, 'messages' => $messages]);
    }

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:' . (int) config('erp_ai.max_input_chars', 4000),
            'thread_id' => 'nullable|uuid',
            'image' => 'nullable|string',
        ]);
        $principal = $this->principal($request);
        if ($denial = $this->accessDenial($principal)) {
            return $denial;
        }

        $existingThreadId = $validated['thread_id'] ?? null;
        $threadId = $existingThreadId ?? (string) Str::uuid();
        $question = $validated['message'];
        $imageInput = $validated['image'] ?? null;
        $quotaDate = now('UTC')->toDateString();
        try {
            $model = $this->modelGateway->resolveApprovedModel();
            $admitted = DB::transaction(function () use ($principal, $existingThreadId, $threadId, $question, $quotaDate, $model): bool {
                if ($existingThreadId && !$this->ownedThreads($principal)->where('id', $threadId)->lockForUpdate()->first()) {
                    return false;
                }

                $this->modelGateway->admitRequest($principal, $quotaDate, $threadId);
                if ($existingThreadId === null) {
                    $this->insertThread($principal, $threadId, Str::limit($question, 60));
                }

                DB::table('ai_runs')->insert([
                    'id' => $principal->requestId,
                    'thread_id' => $threadId,
                    'actor_id' => $principal->actorId,
                    ...$this->scope($principal),
                    'agent_key' => 'erp_copilot',
                    'model_provider' => $model['provider'],
                    'model_key' => $model['model'],
                    'prompt_version' => 'v1',
                    'status' => 'running',
                    'request_hash' => hash('sha256', $question),
                    'started_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return true;
            }, 3);
        } catch (TooManyRequestsHttpException $error) {
            return $this->error('AI_QUOTA_EXCEEDED', $error->getMessage(), 429);
        } catch (ServiceUnavailableHttpException $error) {
            return $this->error('AI_DISABLED', $error->getMessage(), 503);
        } catch (Throwable $error) {
            Log::warning('AI chat admission failed.', ['exception_type' => $error::class]);
            return $this->error('AI_INTERNAL_ERROR', 'The ERP could not start this request.', 500);
        }

        if (!$admitted) {
            return $this->threadNotFound();
        }

        $run = DB::table('ai_runs')->where('company_id', $principal->companyId)->where('id', $principal->requestId);

        try {
            $history = $this->messages($threadId, newestFirst: true)->limit(8)
                ->get(['role', 'content', 'meta'])->reverse()
                ->filter(fn(stdClass $message) => $this->canReplay($principal, json_decode($message->meta, true) ?? []))
                ->map(fn(stdClass $message) => new Message($message->role, mb_substr($message->content, 0, 2000)))
                ->values()->all();

            $attachments = [];
            if (filled($imageInput)) {
                try {
                    $attachments[] = DeepSeekAgent::createImageAttachment($imageInput);
                } catch (Throwable) {
                    // Fall back gracefully if image payload is unparseable
                }
            }

            $activeEntities = DB::table('ai_thread_entities')
                ->where('thread_id', $threadId)
                ->where('company_id', $principal->companyId)
                ->where('branch_id', $principal->branchId)
                ->where('financial_year_id', $principal->financialYearId)
                ->whereIn('entity_type', array_keys(array_filter([
                    'customer' => $principal->hasTool('receivables.invoices.v1'),
                    'invoice' => $principal->hasTool('receivables.invoice_allocations.v1'),
                    'supplier' => $principal->hasTool('payables.summary.v1'),
                ])))
                ->latest('updated_at')
                ->limit(5)
                ->get(['entity_type', 'entity_id', 'as_of'])
                ->map(fn(stdClass $r): array => (array) $r)
                ->all();

            $copilot = new ErpCopilot(
                $principal,
                $history,
                fn(PendingStep $step, Closure $next) => $this->modelGateway->budgetStep($principal, $quotaDate, $step, $next),
                $activeEntities
            );
            $response = $copilot->prompt($question, attachments: $attachments, provider: $model['provider'], model: $model['model'], timeout: (int) config('erp_ai.timeout', 90));

            Log::info('ERP Copilot response diagnostics', [
                'run_id' => $principal->requestId,
                'model' => $model['model'],
                'text_length' => mb_strlen($response->text ?? ''),
                'tool_results' => $response->toolResults->count(),
                'input_tokens' => $response->usage->inputTokens ?? 0,
                'output_tokens' => $response->usage->outputTokens ?? 0,
            ]);

            $evidence = $this->evidence($response);
            $rawText = trim((string) ($response->text ?? ''));
            $answerStatus = $evidence['answer_status'];

            if (!empty($evidence['facts'])) {
                $answer = $rawText !== ''
                    ? $rawText
                    : app(VerifiedSummaryBuilder::class)->build($evidence['facts'], $evidence['warnings']);
            } else {
                $answer = $rawText !== ''
                    ? $rawText
                    : match ($answerStatus) {
                        'no_data' => 'The ERP found no matching data for this request.',
                        'tool_error' => 'The ERP could not complete this check. Review the details below.',
                        default => 'The copilot could not produce a verified answer. Review any available ERP results below.',
                    };
            }

            DB::transaction(function () use ($principal, $threadId, $question, $answer, $response, $evidence, $run): void {
                $this->storeTurn($principal, $threadId, $question, $answer, $response, $evidence);
                $this->ownedThreads($principal)->where('id', $threadId)->update(['updated_at' => now()]);
                $run->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
            }, 3);

            return response()->json([
                'run_id' => $principal->requestId,
                'thread_id' => $threadId,
                'status' => 'completed',
                'answer_status' => $answerStatus,
                'message' => $answer,
                ...$evidence,
                'scope' => $this->scope($principal),
                'usage' => [
                    ...$response->usage->toArray(),
                    'tool_count' => $response->toolResults->count(),
                ],
            ]);
        } catch (Throwable $error) {
            [$code, $message, $status] = match (true) {
                $error instanceof TooManyRequestsHttpException => ['AI_QUOTA_EXCEEDED', $error->getMessage(), 429],
                $error instanceof ServiceUnavailableHttpException => ['AI_DISABLED', $error->getMessage(), 503],
                $error instanceof AiException || $error instanceof RequestException || $error instanceof ConnectionException
                => ['AI_PROVIDER_UNAVAILABLE', 'The AI provider could not complete this request.', 502],
                default => ['AI_INTERNAL_ERROR', 'The ERP could not complete this request.', 500],
            };
            $run->update(['status' => 'failed', 'error_code' => $code, 'completed_at' => now(), 'updated_at' => now()]);
            Log::warning('AI chat run failed.', [
                'run_id' => $principal->requestId,
                'company_id' => $principal->companyId,
                'error_code' => $code,
                'exception_type' => $error::class,
                'file' => $error->getFile() . ':' . $error->getLine(),
            ]);

            return response()->json([
                'error_code' => $code,
                'error_message' => $message,
                'answer_status' => $code === 'AI_PROVIDER_UNAVAILABLE' ? 'provider_error' : 'tool_error',
            ], $status);
        }
    }

    private function principal(Request $request): AiPrincipal
    {
        abort_if($request->query->has('branch_scope'), 422, 'Copilot uses the selected branch.');
        $actor = $request->user()->id;
        $context = $this->contextResolver->forActor(userId: $actor);
        $workspaceScope = $request->header('X-AI-Scope');
        abort_if($workspaceScope !== null && $workspaceScope !== implode('/', [
            $context->companyId,
            $context->branchId,
            $context->financialYearId,
        ]), 409, 'The active ERP context changed. Reload the copilot workspace.');

        return $this->principalFactory->createForUser($actor, $context->companyId, $context->branchId, $context->financialYearId);
    }

    private function accessDenial(AiPrincipal $principal): ?JsonResponse
    {
        if (!$this->featureGate->isEnabledForCompany($principal->companyId)) {
            return $this->error('AI_DISABLED', 'AI capabilities are disabled for this company.', 503);
        }
        if (!$principal->isActive || $principal->permittedToolNames === []) {
            return $this->error('AI_TOOL_FORBIDDEN', 'You do not have permission to use AI tools for this company.', 403);
        }

        return null;
    }

    private function scope(AiPrincipal $principal): array
    {
        return [
            'company_id' => $principal->companyId,
            'branch_id' => $principal->branchId,
            'financial_year_id' => $principal->financialYearId,
        ];
    }

    private function ownedThreads(AiPrincipal $principal): Builder
    {
        return DB::table('ai_thread_scopes')->where($this->scope($principal))
            ->where('user_id', $principal->actorId)->where('status', 'active')
            ->where(fn(Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    private function insertThread(AiPrincipal $principal, string $id, string $title): void
    {
        DB::table('ai_thread_scopes')->insert([
            'id' => $id,
            'sdk_conversation_id' => $id,
            'user_id' => $principal->actorId,
            ...$this->scope($principal),
            'title' => $title,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function messages(string $authorizedThreadId, bool $newestFirst = false): Builder
    {
        $direction = $newestFirst ? 'desc' : 'asc';

        return DB::table('agent_conversation_messages')->where('conversation_id', $authorizedThreadId)
            ->where('agent', 'erp_copilot')->where('status', 'completed')->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at', $direction)
            // Old UUIDv4 turns could share one timestamp. Put the user before the assistant on ties.
            ->orderByRaw("CASE WHEN SUBSTRING(id, 15, 1) = '7' THEN 2 WHEN role = 'user' THEN 0 ELSE 1 END {$direction}")
            ->orderBy('id', $direction);
    }

    private function canReplay(AiPrincipal $principal, array $metadata): bool
    {
        $context = new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId);
        $required = [];
        foreach ($metadata['facts'] ?? [] as $fact) {
            $tool = $fact['tool'] ?? '';
            if (!$principal->hasTool($tool)) {
                return false;
            }

            if (
                !isset($metadata['answer_status']) && in_array($tool, [
                    'documents.preview.v1',
                    'orders.open.v1',
                    'inventory.stock_summary.v1',
                    'knowledge.query.v1',
                    'receivables.invoice_allocations.v1',
                    'sales.summary.v1',
                ], true)
            ) {
                return false;
            }

            $data = $fact['data'] ?? [];
            if ($tool === 'documents.preview.v1') {
                $required[] = ($data['document_type'] ?? null) === 'purchase_order' ? 'purchases-index' : 'sales-index';
            }
            if ($tool === 'orders.open.v1') {
                foreach ($data['included_types'] ?? [] as $type) {
                    $required[] = $type === 'purchase' ? 'purchases-index' : 'sales-index';
                }
            }
            if ($tool === 'inventory.stock_summary.v1' && ($data['valuation_visible'] ?? true)) {
                $required[] = 'purchases-index';
            }
            if ($tool === 'purchase_order.create.v1') {
                $required[] = 'purchases-add';
            }
        }

        $required = array_unique($required);
        return $required === [] || array_diff($required, app(CommercialPermission::class)->allowed($required, $context, $principal->actorId)) === [];
    }

    private function evidence(AgentResponse $response): array
    {
        $facts = $sources = $warnings = [];
        $failed = $unreconciled = $noData = false;
        foreach ($response->toolResults as $result) {
            $payload = $result->successful() ? json_decode($result->text(), true) : null;
            if (($payload['status'] ?? null) === 'needs_input') {
                $warnings[] = $payload['message'] ?? 'Additional details are required to complete this proposal.';
                continue;
            }

            if (($payload['status'] ?? null) !== 'ok') {
                $failed = true;
                $errorCode = $payload['error_code'] ?? null;
                $specificWarning = match ($errorCode) {
                    'INVALID_FINANCIAL_YEAR_DATE' => $payload['error_message'] ?? 'Choose a date inside the active financial year.',
                    'ENTITY_NOT_FOUND' => 'Target customer, invoice, or entity was not found in this company/branch.',
                    'AI_INPUT_VALIDATION_FAILED' => 'Query parameters were outside allowable limits.',
                    'AI_TOOL_LIMIT_EXCEEDED' => 'The tool limit for this request was reached.',
                    'AI_TOOL_FORBIDDEN' => 'Access denied: insufficient permissions to query this report or warehouse.',
                    'AI_PRINCIPAL_INACTIVE' => 'Authentication expired: user or company session is inactive.',
                    'AI_DOMAIN_EXECUTION_FAILURE' => 'Financial reporting service could not complete calculation for the requested date or parameters.',
                    default => 'An ERP tool could not complete its check.',
                };
                if (!empty($payload['warnings'])) {
                    array_push($warnings, ...$payload['warnings']);
                } else {
                    $warnings[] = $specificWarning;
                }
                continue;
            }

            $reconciled = $payload['reconciled'] ?? $payload['reconciliation']['is_reconciled'] ?? true;
            $unreconciled = $unreconciled || !$reconciled;
            if (($payload['row_count'] ?? null) === 0) {
                $noData = true;
                array_push($warnings, ...($payload['warnings'] ?? []));
                continue;
            }

            $facts[] = [
                'tool' => $payload['tool'],
                'as_of' => $payload['as_of'],
                'data' => $payload['data'],
                'reconciled' => $reconciled,
            ];
            $toolTitle = match ($payload['tool'] ?? '') {
                'inventory.stock_summary.v1' => 'Warehouse stock report',
                'receivables.outstanding.v1' => 'Customer receivables ageing',
                'receivables.invoices.v1' => 'Customer invoice breakdown',
                'receivables.invoice_allocations.v1' => 'Invoice payment allocations',
                'sales.summary.v1' => 'Sales & POS analytics',
                'payables.summary.v1' => 'Accounts payable ageing',
                'accounting.statements.v1' => 'Financial statements',
                'tax.gst_summary.v1' => 'GST tax breakdown',
                'copilot.help.v1' => 'Copilot capabilities catalog',
                'manufacturing.boms.v1' => 'Manufacturing Bill of Materials',
                'jobwork.status.v1' => 'Job work processing report',
                'orders.open.v1' => 'Open orders & fulfilment report',
                'documents.preview.v1' => 'Document preview',
                'knowledge.query.v1' => 'Company policy & terms',
                'purchase_order.create.v1' => 'Purchase order proposal',
                default => 'Authoritative ERP report',
            };
            foreach ($payload['source_refs'] ?? [] as $href) {
                if (count($sources) < 20 && is_string($href) && preg_match('~^/[a-z0-9][^\\\\\x00-\x20]*$~iD', $href)) {
                    $sources[$href] = ['title' => $toolTitle, 'href' => $href];
                }
            }
            if (!empty($payload['warnings'])) {
                array_push($warnings, ...$payload['warnings']);
            }
        }

        $status = match (true) {
            $facts !== [] && $failed => 'partial',
            $facts !== [] && $unreconciled => 'unreconciled',
            $facts !== [] => 'verified',
            $failed => 'tool_error',
            $noData => 'no_data',
            default => 'unsupported',
        };

        return ['answer_status' => $status, 'facts' => $facts, 'sources' => array_values($sources), 'warnings' => array_values(array_unique($warnings))];
    }

    private function storeTurn(AiPrincipal $principal, string $threadId, string $question, string $answer, AgentResponse $response, array $evidence): void
    {
        $common = [
            'conversation_id' => $threadId,
            'agent' => 'erp_copilot',
            'attachments' => '[]',
            'steps' => '[]',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('agent_conversation_messages')->insert([
            [
                ...$common,
                'id' => (string) Str::uuid7(),
                'participant_type' => 'user',
                'participant_id' => $principal->actorId,
                'role' => 'user',
                'content' => $question,
                'usage' => '[]',
                'meta' => '[]',
            ],
            [
                ...$common,
                'id' => (string) Str::uuid7(),
                'participant_type' => 'agent',
                'participant_id' => null,
                'role' => 'assistant',
                'content' => $answer,
                'usage' => json_encode($response->usage, JSON_THROW_ON_ERROR),
                'meta' => json_encode($evidence, JSON_THROW_ON_ERROR),
            ],
        ]);

        // Persist verified entities into ai_thread_entities for multi-turn grounding (AI-005)
        foreach ($evidence['facts'] ?? [] as $fact) {
            $tool = $fact['tool'] ?? '';
            $data = $fact['data'] ?? [];
            $asOf = $fact['as_of'] ?? null;

            if ($tool === 'receivables.outstanding.v1') {
                foreach ($data['top_customers'] ?? [] as $cust) {
                    if (!empty($cust['customer_id'])) {
                        DB::table('ai_thread_entities')->updateOrInsert(
                            [
                                'thread_id' => $threadId,
                                'company_id' => $principal->companyId,
                                'entity_type' => 'customer',
                                'entity_id' => $cust['customer_id'],
                            ],
                            [
                                'branch_id' => $principal->branchId,
                                'financial_year_id' => $principal->financialYearId,
                                'entity_name' => $cust['customer_name'] ?? null,
                                'as_of' => $asOf,
                                'metadata' => json_encode($cust, JSON_THROW_ON_ERROR),
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                    }
                }
            } elseif ($tool === 'receivables.invoices.v1') {
                if (!empty($data['customer']['id'])) {
                    DB::table('ai_thread_entities')->updateOrInsert(
                        [
                            'thread_id' => $threadId,
                            'company_id' => $principal->companyId,
                            'entity_type' => 'customer',
                            'entity_id' => $data['customer']['id'],
                        ],
                        [
                            'branch_id' => $principal->branchId,
                            'financial_year_id' => $principal->financialYearId,
                            'entity_name' => $data['customer']['name'] ?? null,
                            'as_of' => $asOf,
                            'metadata' => json_encode($data['customer'], JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
                foreach ($data['invoices'] ?? [] as $inv) {
                    if (!empty($inv['open_item_id'])) {
                        DB::table('ai_thread_entities')->updateOrInsert(
                            [
                                'thread_id' => $threadId,
                                'company_id' => $principal->companyId,
                                'entity_type' => 'invoice',
                                'entity_id' => $inv['open_item_id'],
                            ],
                            [
                                'branch_id' => $principal->branchId,
                                'financial_year_id' => $principal->financialYearId,
                                'entity_name' => $inv['invoice_number'] ?? null,
                                'as_of' => $asOf,
                                'metadata' => json_encode($inv, JSON_THROW_ON_ERROR),
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                    }
                }
            } elseif ($tool === 'payables.summary.v1') {
                foreach ($data['top_suppliers'] ?? [] as $sup) {
                    if (!empty($sup['supplier_id'])) {
                        DB::table('ai_thread_entities')->updateOrInsert(
                            [
                                'thread_id' => $threadId,
                                'company_id' => $principal->companyId,
                                'entity_type' => 'supplier',
                                'entity_id' => $sup['supplier_id'],
                            ],
                            [
                                'branch_id' => $principal->branchId,
                                'financial_year_id' => $principal->financialYearId,
                                'entity_name' => $sup['name'] ?? null,
                                'as_of' => $asOf,
                                'metadata' => json_encode($sup, JSON_THROW_ON_ERROR),
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                    }
                }
            }
        }
    }

    private function threadNotFound(): JsonResponse
    {
        return $this->error('AI_THREAD_NOT_FOUND', 'Thread not found in the active ERP context.', 404);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error_code' => $code, 'error_message' => $message], $status);
    }
}
