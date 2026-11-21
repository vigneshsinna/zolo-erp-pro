<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Knowledge\BusinessWorkflowMetrics;
use App\Ai\Knowledge\BusinessWorkflowPlanner;
use App\Ai\Knowledge\BusinessWorkflowProgress;
use App\Ai\Services\ActionSecurity;
use App\Ai\Services\AiPrincipalFactory;
use App\Http\Controllers\Controller;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only planning and human-driven plan metadata; NO approval/ERP execution endpoint. */
final class AiBusinessWorkflowController extends Controller
{
    public function __construct(
        private readonly AiPrincipalFactory $principals,
        private readonly CompanyContextResolver $resolver,
        private readonly ActionSecurity $security,
        private readonly BusinessWorkflowPlanner $planner,
        private readonly BusinessWorkflowProgress $progress,
        private readonly BusinessWorkflowMetrics $metrics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principal($request);
        $this->assertAvailable($principal);
        $rows = DB::table('ai_business_workflows')->where([
            'company_id' => $principal->companyId, 'branch_id' => $principal->branchId,
            'financial_year_id' => $principal->financialYearId, 'actor_id' => $principal->actorId,
        ])->orderByDesc('created_at')->limit(20)->get([
            'id', 'process_id', 'status', 'revision', 'knowledge_hash', 'updated_at',
        ]);
        return response()->json(['workflows' => $rows, 'read_only_list' => true]);
    }

    public function preview(Request $request, string $processId): JsonResponse
    {
        return response()->json($this->planner->preview($this->principal($request), $processId));
    }

    public function store(Request $request): JsonResponse
    {
        $inputs = $request->validate([
            'process_id' => 'required|string|max:150',
            'thread_id' => 'nullable|uuid', 'task_id' => 'nullable|uuid',
        ]);
        return response()->json($this->planner->start(
            $this->principal($request), $inputs['process_id'],
            $inputs['thread_id'] ?? null, $inputs['task_id'] ?? null,
        ), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->planner->inspect($this->principal($request), $id));
    }

    public function correct(Request $request, string $id): JsonResponse
    {
        $inputs = $request->validate([
            'expected_revision' => 'required|integer|min:1',
            'patch' => 'required|array|min:1|max:5',
        ]);
        return response()->json($this->planner->correct(
            $this->principal($request), $id, (int) $inputs['expected_revision'], $inputs['patch'],
        ));
    }

    public function pause(Request $request, string $id): JsonResponse
    {
        return response()->json($this->planner->pause($this->principal($request), $id, $this->revision($request)));
    }

    public function resume(Request $request, string $id): JsonResponse
    {
        return response()->json($this->planner->resume($this->principal($request), $id, $this->revision($request)));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return response()->json($this->planner->cancel($this->principal($request), $id, $this->revision($request)));
    }

    public function linkProposal(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => 'required|integer|min:1',
            'step_key' => 'required|string|max:100',
            'proposal_id' => 'required|uuid',
        ]);
        return response()->json($this->progress->linkProposal(
            $this->principal($request), $id, (int) $data['expected_revision'],
            $data['step_key'], $data['proposal_id'],
        ));
    }

    public function clarify(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => 'required|integer|min:1',
            'step_key' => 'required|string|max:100',
            'inputs' => 'required|array|min:1',
        ]);
        return response()->json($this->progress->completeClarification(
            $this->principal($request), $id, (int) $data['expected_revision'],
            $data['step_key'], $data['inputs'],
        ));
    }

    public function validateStep(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => 'required|integer|min:1',
            'step_key' => 'required|string|max:100',
            'validation' => 'required|array|min:1',
        ]);
        return response()->json($this->progress->completeValidation(
            $this->principal($request), $id, (int) $data['expected_revision'],
            $data['step_key'], $data['validation'],
        ));
    }

    public function verifyEvent(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => 'required|integer|min:1',
            'step_key' => 'required|string|max:100',
            'evidence' => 'required|array|min:1',
        ]);
        return response()->json($this->progress->verifyExternalEvent(
            $this->principal($request), $id, (int) $data['expected_revision'],
            $data['step_key'], $data['evidence'],
        ));
    }

    public function verifyReturn(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => 'required|integer|min:1',
            'step_key' => 'required|string|max:100',
            'job_work_receipt_id' => 'required|integer|min:1',
        ]);
        return response()->json($this->progress->verifyPhysicalReturn(
            $this->principal($request), $id, (int) $data['expected_revision'],
            $data['step_key'], (int) $data['job_work_receipt_id'],
        ));
    }

    public function reconcile(Request $request, string $id): JsonResponse
    {
        return response()->json($this->progress->reconcile($this->principal($request), $id, $this->revision($request)));
    }

    public function metrics(Request $request): JsonResponse
    {
        return response()->json($this->metrics->summary($this->principal($request)));
    }

    private function revision(Request $request): int
    {
        return (int) $request->validate(['expected_revision' => 'required|integer|min:1'])['expected_revision'];
    }

    private function assertAvailable(AiPrincipal $principal): void
    {
        if (!config('ai_business_knowledge.enabled', false)
            || !config('ai_business_knowledge.planner_enabled', false)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Business workflow planning is disabled.');
        }
        $this->security->assertEnabled($principal);
        $this->security->context($principal);
    }

    private function principal(Request $request): AiPrincipal
    {
        abort_if($request->query->has('branch_scope'), 422, 'Copilot uses the selected branch.');
        $actor = $request->user()->id;
        $context = $this->resolver->forActor($request->attributes->get(CompanyContext::class), $actor);
        $scope = $request->header('X-AI-Scope');
        abort_if($scope !== null && $scope !== implode('/', [
            $context->companyId, $context->branchId, $context->financialYearId,
        ]), 409, 'Active ERP context changed. Reload Copilot.');
        return $this->principals->createForUser(
            $actor, $context->companyId, $context->branchId, $context->financialYearId);
    }
}
