<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Ai\Agents\DeepSeekAgent;
use App\Ai\Agents\ErpCopilot;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Http\Controllers\Controller;
use App\Services\Platform\CompanyContextResolver;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
    ) {}

    public function settings(Request $request): JsonResponse
    {
        $principal = $this->principal($request);

        return response()->json([
            'enabled' => $this->featureGate->isEnabledForCompany($principal->companyId),
            'read_only' => $this->featureGate->isReadOnly($principal->companyId),
            'allowed_tools' => $principal->permittedToolNames,
            ...$this->modelGateway->resolveApprovedModel(),
            'scope' => $this->scope($principal),
        ]);
    }

    public function monitoring(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!in_array((int) ($user->role_id ?? 0), [1, 2], true)) {
            return $this->error('AI_FORBIDDEN', 'Administrator privileges are required to view AI telemetry and monitoring.', 403);
        }

        $principal = $this->principal($request);
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
            ->map(function (stdClass $message): array {
                $metadata = json_decode($message->meta, true) ?? [];
                unset($message->meta);

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
        $model = $this->modelGateway->resolveApprovedModel();

        try {
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
        }

        if (!$admitted) {
            return $this->threadNotFound();
        }

        $run = DB::table('ai_runs')->where('company_id', $principal->companyId)->where('id', $principal->requestId);

        try {
            $history = $this->messages($threadId, newestFirst: true)->limit(8)
                ->get(['role', 'content'])->reverse()
                ->map(fn (stdClass $message) => new Message($message->role, mb_substr($message->content, 0, 2000)))
                ->values()->all();

            $attachments = [];
            if (filled($imageInput)) {
                try {
                    $attachments[] = DeepSeekAgent::createImageAttachment($imageInput);
                } catch (Throwable) {
                    // Fall back gracefully if image payload is unparseable
                }
            }

            $copilot = new ErpCopilot(
                $principal,
                $history,
                fn (PendingStep $step, Closure $next) => $this->modelGateway->budgetStep($principal, $quotaDate, $step, $next),
            );
            $response = $copilot->prompt($question, attachments: $attachments, provider: $model['provider'], model: $model['model'], timeout: (int) config('erp_ai.timeout', 90));

            $evidence = $this->evidence($response);
            $rawText = trim($response->text);

            if (!empty($evidence['facts'])) {
                $answer = $rawText !== ''
                    ? $rawText
                    : 'Verified ERP facts have been retrieved for this request. Review the summary card below.';
            } elseif (!empty($evidence['warnings'])) {
                $warningDetails = implode(' ', $evidence['warnings']);
                $answer = $rawText !== ''
                    ? $rawText . "\n\n> [!WARNING]\n> {$warningDetails}"
                    : "The copilot attempted to query ERP records, but could not complete the check: {$warningDetails}";
            } else {
                $answer = 'The copilot could not produce a verified answer. Review any available ERP results below.';
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
                default => ['AI_PROVIDER_UNAVAILABLE', 'The copilot could not complete this request. The upstream AI provider timed out or is temporarily unavailable.', 502],
            };
            $run->update(['status' => 'failed', 'error_code' => $code, 'completed_at' => now(), 'updated_at' => now()]);
            Log::warning('AI chat run failed.', [
                'run_id' => $principal->requestId,
                'company_id' => $principal->companyId,
                'error_code' => $code,
                'exception_type' => $error::class,
            ]);

            return $this->error($code, $message, $status);
        }
    }

    private function principal(Request $request): AiPrincipal
    {
        $actor = $request->user()->id;
        $context = $this->contextResolver->forActor(userId: $actor);
        $workspaceScope = $request->header('X-AI-Scope');
        abort_if($workspaceScope !== null && $workspaceScope !== implode('/', [
            $context->companyId, $context->branchId, $context->financialYearId,
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
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
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

    private function evidence(AgentResponse $response): array
    {
        $facts = $sources = $warnings = [];
        foreach ($response->toolResults as $result) {
            $payload = $result->successful() ? json_decode($result->text(), true) : null;
            if (($payload['status'] ?? null) !== 'ok') {
                $errorCode = $payload['error_code'] ?? null;
                $specificWarning = match ($errorCode) {
                    'AI_INPUT_VALIDATION_FAILED' => 'Query parameters were outside allowable limits.',
                    'AI_TOOL_FORBIDDEN' => 'Access denied: insufficient permissions to query this report.',
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

            $facts[] = [
                'tool' => $payload['tool'],
                'as_of' => $payload['as_of'],
                'data' => $payload['data'],
            ];
            $toolTitle = match ($payload['tool'] ?? '') {
                'inventory.stock_summary.v1' => 'Warehouse stock report',
                'receivables.outstanding.v1' => 'Customer receivables ageing',
                default => 'Authoritative ERP report',
            };
            foreach ($payload['source_refs'] ?? [] as $href) {
                $sources[$href] = ['title' => $toolTitle, 'href' => $href];
            }
            if (!empty($payload['warnings'])) {
                array_push($warnings, ...$payload['warnings']);
            }
        }

        return ['facts' => $facts, 'sources' => array_values($sources), 'warnings' => array_values(array_unique($warnings))];
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
