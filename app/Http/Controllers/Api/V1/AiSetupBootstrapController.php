<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Ai\Services\BootstrapProposalService;
use App\Ai\Services\BootstrapSetupSecurity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiSetupBootstrapController extends Controller
{
    public function __construct(
        private readonly BootstrapSetupSecurity $security,
        private readonly BootstrapProposalService $proposals,
    ) {}

    public function initiateTask(Request $request): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $principal = $this->security->resolvePrincipal($actorId);
        $task = $this->proposals->initiateTask($principal);

        return response()->json($task, 201);
    }

    public function preview(Request $request, string $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $taskToken = $request->header('X-Setup-Task-Token') ?: (string) $request->input('task_token');
        $principal = $this->security->resolvePrincipal($actorId, $taskToken ?: null);

        $actionKey = (string) $request->input('action_key', 'setup.company.create.v1');
        $slots = (array) $request->input('slots', $request->except(['action_key', 'task_token']));

        $proposal = $this->proposals->createProposal($principal, $id, $actionKey, $slots);

        return response()->json([
            'status' => $proposal['status'],
            'proposal_id' => $proposal['id'],
            'action_key' => $proposal['action_key'],
            'version' => (int) $proposal['version'],
            'payload_hash' => $proposal['payload_hash'],
            'source_state_hash' => $proposal['source_state_hash'],
            'preview' => json_decode((string) $proposal['preview_json'], true, flags: JSON_THROW_ON_ERROR),
            'expires_at' => $proposal['expires_at'],
        ]);
    }

    public function changeRequest(Request $request, string $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $taskToken = $request->header('X-Setup-Task-Token') ?: (string) $request->input('task_token');
        $principal = $this->security->resolvePrincipal($actorId, $taskToken ?: null);

        $changes = (array) $request->input('changes', []);
        $expectedVersion = (int) $request->input('expected_version', 1);

        $proposal = $this->proposals->applyChangeRequest($principal, $id, $changes, $expectedVersion);

        return response()->json([
            'status' => $proposal['status'],
            'proposal_id' => $proposal['id'],
            'version' => (int) $proposal['version'],
            'payload_hash' => $proposal['payload_hash'],
            'source_state_hash' => $proposal['source_state_hash'],
            'preview' => json_decode((string) $proposal['preview_json'], true, flags: JSON_THROW_ON_ERROR),
            'expires_at' => $proposal['expires_at'],
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $taskToken = $request->header('X-Setup-Task-Token') ?: (string) $request->input('task_token');
        $principal = $this->security->resolvePrincipal($actorId, $taskToken ?: null);

        $expectedVersion = (int) $request->input('expected_version', 1);
        $signature = $request->input('approval_signature') ? (string) $request->input('approval_signature') : null;

        $result = $this->proposals->approveAndExecute($principal, $id, $expectedVersion, $signature);

        return response()->json($result);
    }
}

