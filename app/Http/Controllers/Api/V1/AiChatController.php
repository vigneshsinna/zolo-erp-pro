<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Ai\Agents\ErpCopilot;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Http\Controllers\Controller;
use App\Services\Platform\CompanyContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AiChatController extends Controller
{
    public function __construct(
        protected CompanyContextResolver $contextResolver,
        protected AiPrincipalFactory $principalFactory,
        protected AiFeatureGate $featureGate,
        protected ApprovedModelGateway $modelGateway
    ) {}

    /**
     * Get current AI operating settings and permitted tools for the authenticated company.
     */
    public function settings(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error_code' => 'AI_UNAUTHENTICATED', 'error_message' => 'Authentication required.'], 401);
        }

        try {
            $context = $this->contextResolver->forActor(userId: $user->id);
            $principal = $this->principalFactory->createForUser($user->id, $context->companyId, $context->branchId, $context->financialYearId);
            $modelConfig = $this->modelGateway->resolveApprovedModel($principal);

            return response()->json([
                'enabled' => $this->featureGate->isEnabledForCompany($context->companyId),
                'read_only' => $this->featureGate->isReadOnly($context->companyId),
                'allowed_tools' => $principal->permittedToolNames,
                'provider' => $modelConfig['provider'],
                'model' => $modelConfig['model'],
                'scope' => [
                    'company_id' => $context->companyId,
                    'branch_id' => $context->branchId,
                    'financial_year_id' => $context->financialYearId,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json(['error_code' => 'AI_SCOPE_INVALID', 'error_message' => $e->getMessage()], 403);
        }
    }

    /**
     * List owned conversation threads for the current company context.
     */
    public function threads(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error_code' => 'AI_UNAUTHENTICATED', 'error_message' => 'Authentication required.'], 401);
        }

        try {
            $context = $this->contextResolver->forActor(userId: $user->id);

            $threads = DB::table('ai_thread_scopes')
                ->where('user_id', $user->id)
                ->where('company_id', $context->companyId)
                ->where('status', 'active')
                ->orderBy('updated_at', 'desc')
                ->select(['id', 'title', 'created_at', 'updated_at'])
                ->limit(50)
                ->get();

            return response()->json([
                'threads' => $threads,
                'scope' => [
                    'company_id' => $context->companyId,
                    'branch_id' => $context->branchId,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json(['error_code' => 'AI_SCOPE_INVALID', 'error_message' => $e->getMessage()], 403);
        }
    }

    /**
     * Create a new owned conversation thread.
     */
    public function createThread(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error_code' => 'AI_UNAUTHENTICATED', 'error_message' => 'Authentication required.'], 401);
        }

        try {
            $context = $this->contextResolver->forActor(userId: $user->id);

            $title = $request->input('title', 'New AI Session');
            $threadId = (string) Str::uuid();

            DB::table('ai_thread_scopes')->insert([
                'id' => $threadId,
                'sdk_conversation_id' => $threadId,
                'user_id' => $user->id,
                'company_id' => $context->companyId,
                'branch_id' => $context->branchId,
                'financial_year_id' => $context->financialYearId,
                'title' => Str::limit((string) $title, 100),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'id' => $threadId,
                'title' => $title,
                'created_at' => now()->toIso8601String(),
            ], 201);
        } catch (Throwable $e) {
            return response()->json(['error_code' => 'AI_SCOPE_INVALID', 'error_message' => $e->getMessage()], 403);
        }
    }

    /**
     * Fetch owned conversation thread and its message history.
     */
    public function showThread(string $id, Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error_code' => 'AI_UNAUTHENTICATED', 'error_message' => 'Authentication required.'], 401);
        }

        try {
            $context = $this->contextResolver->forActor(userId: $user->id);

            $thread = DB::table('ai_thread_scopes')
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->where('company_id', $context->companyId)
                ->first();

            if (!$thread) {
                return response()->json(['error_code' => 'AI_THREAD_NOT_FOUND', 'error_message' => 'Thread not found or access denied.'], 404);
            }

            $messages = DB::table('agent_conversation_messages')
                ->where('conversation_id', $id)
                ->orderBy('created_at', 'asc')
                ->select(['id', 'role', 'content', 'created_at'])
                ->get();

            return response()->json([
                'thread' => $thread,
                'messages' => $messages,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error_code' => 'AI_SCOPE_INVALID', 'error_message' => $e->getMessage()], 403);
        }
    }

    /**
     * Send a question to ErpCopilot, executing authoritative tools and persisting thread state.
     */
    public function chat(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error_code' => 'AI_UNAUTHENTICATED', 'error_message' => 'Authentication required.'], 401);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'thread_id' => 'nullable|string|size:36',
            'options' => 'nullable|array',
        ]);

        try {
            $context = $this->contextResolver->forActor(userId: $user->id);

            // 1. Feature Gate check
            if (!$this->featureGate->isEnabledForCompany($context->companyId)) {
                return response()->json([
                    'error_code' => 'AI_DISABLED',
                    'error_message' => 'AI capabilities are currently disabled for this company or system-wide.',
                ], 503);
            }

            // 2. Resolve principal
            $principal = $this->principalFactory->createForUser(
                userId: $user->id,
                companyId: $context->companyId,
                branchId: $context->branchId,
                financialYearId: $context->financialYearId
            );

            if (!$principal->isActive || empty($principal->permittedToolNames)) {
                return response()->json([
                    'error_code' => 'AI_TOOL_FORBIDDEN',
                    'error_message' => 'You do not have permission to execute AI tools for this company.',
                ], 403);
            }

            // 3. Quota check
            try {
                $this->modelGateway->assertQuotaAvailable($principal);
            } catch (\RuntimeException $re) {
                return response()->json([
                    'error_code' => 'AI_QUOTA_EXCEEDED',
                    'error_message' => $re->getMessage(),
                ], 429);
            }

            // 4. Resolve Approved Model
            $modelConfig = $this->modelGateway->resolveApprovedModel($principal);

            // 5. Thread verification or creation
            $threadId = $validated['thread_id'] ?? null;
            if ($threadId !== null) {
                $thread = DB::table('ai_thread_scopes')
                    ->where('id', $threadId)
                    ->where('user_id', $user->id)
                    ->where('company_id', $context->companyId)
                    ->first();

                if (!$thread) {
                    return response()->json([
                        'error_code' => 'AI_THREAD_NOT_FOUND',
                        'error_message' => 'Specified conversation thread does not exist or access was denied.',
                    ], 404);
                }
            } else {
                $threadId = (string) Str::uuid();
                DB::table('ai_thread_scopes')->insert([
                    'id' => $threadId,
                    'sdk_conversation_id' => $threadId,
                    'user_id' => $user->id,
                    'company_id' => $context->companyId,
                    'branch_id' => $context->branchId,
                    'financial_year_id' => $context->financialYearId,
                    'title' => Str::limit($validated['message'], 60),
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 6. Record Run
            $runId = (string) Str::uuid();
            DB::table('ai_runs')->insert([
                'id' => $runId,
                'thread_id' => $threadId,
                'actor_id' => $user->id,
                'company_id' => $context->companyId,
                'branch_id' => $context->branchId,
                'financial_year_id' => $context->financialYearId,
                'agent_key' => 'erp_copilot',
                'model_provider' => $modelConfig['provider'],
                'model_key' => $modelConfig['model'],
                'prompt_version' => 'v1',
                'status' => 'running',
                'request_hash' => hash('sha256', $validated['message']),
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Save user message in message history
            DB::table('agent_conversation_messages')->insert([
                'id' => (string) Str::uuid(),
                'conversation_id' => $threadId,
                'participant_type' => 'user',
                'participant_id' => $user->id,
                'agent' => 'erp_copilot',
                'role' => 'user',
                'content' => $validated['message'],
                'attachments' => '[]',
                'steps' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 7. Execute Copilot Agent
            $scopedPrincipal = new AiPrincipal(
                actorId: $principal->actorId,
                companyId: $principal->companyId,
                branchId: $principal->branchId,
                financialYearId: $principal->financialYearId,
                permittedToolNames: $principal->permittedToolNames,
                requestId: $runId,
                effectiveRole: $principal->effectiveRole,
                isActive: $principal->isActive
            );

            $copilot = new ErpCopilot($scopedPrincipal);

            try {
                $response = $copilot->prompt($validated['message']);
                $assistantText = (string) $response;
            } catch (Throwable $modelError) {
                Log::warning("AI Provider execution encountered an error: " . $modelError->getMessage(), [
                    'run_id' => $runId,
                    'company_id' => $context->companyId,
                    'user_id' => $user->id,
                ]);

                DB::table('ai_runs')->where('id', $runId)->update([
                    'status' => 'failed',
                    'error_code' => 'AI_PROVIDER_UNAVAILABLE',
                    'updated_at' => now(),
                ]);

                return response()->json([
                    'error_code' => 'AI_PROVIDER_UNAVAILABLE',
                    'error_message' => 'The AI language model provider is currently unreachable or unconfigured. Please ensure provider API keys are active.',
                ], 502);
            }

            // 8. Extract tool execution telemetry from ai_tool_invocations
            $invocations = DB::table('ai_tool_invocations')->where('run_id', $runId)->get();

            $facts = [];
            $sources = [];
            $warnings = [];

            foreach ($invocations as $inv) {
                if ($inv->output_ref) {
                    $sources[] = [
                        'title' => 'Authorised ERP Report',
                        'href' => $inv->output_ref,
                    ];
                }
            }

            // Save assistant response message in message history
            DB::table('agent_conversation_messages')->insert([
                'id' => (string) Str::uuid(),
                'conversation_id' => $threadId,
                'participant_type' => 'agent',
                'participant_id' => null,
                'agent' => 'erp_copilot',
                'role' => 'assistant',
                'content' => $assistantText,
                'attachments' => '[]',
                'steps' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update thread updated_at
            DB::table('ai_thread_scopes')->where('id', $threadId)->update([
                'updated_at' => now(),
            ]);

            // Complete run
            DB::table('ai_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            // Record Usage
            $this->modelGateway->recordUsage($principal, inputTokens: 50, outputTokens: 50);

            return response()->json([
                'run_id' => $runId,
                'thread_id' => $threadId,
                'status' => 'completed',
                'message' => $assistantText,
                'facts' => $facts,
                'scope' => [
                    'company_id' => $context->companyId,
                    'branch_id' => $context->branchId,
                    'financial_year_id' => $context->financialYearId,
                ],
                'sources' => $sources,
                'warnings' => $warnings,
                'usage' => [
                    'tool_count' => count($invocations),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json([
                'error_code' => 'AI_INPUT_INVALID',
                'error_message' => $ve->getMessage(),
                'errors' => $ve->errors(),
            ], 422);
        } catch (\Illuminate\Auth\Access\AuthorizationException $ae) {
            return response()->json([
                'error_code' => 'AI_SCOPE_INVALID',
                'error_message' => $ae->getMessage(),
            ], 403);
        } catch (Throwable $e) {
            Log::error("AI Chat Controller Unhandled Exception: " . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'error_code' => 'AI_SYSTEM_ERROR',
                'error_message' => 'An unexpected internal error occurred.',
            ], 500);
        }
    }
}
