<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Jobs\ExecuteApprovedAction;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\ActionProposalService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

final class AsyncActionDispatch
{
    public function dispatch(AiPrincipal $principal, string $id, int $version): array
    {
        abort_unless(config('ai_infrastructure.async_execution_enabled', false) && config('ai_actions.execution_enabled', false), 403);
        $connection = config('ai_infrastructure.queue_connection', 'redis');
        abort_if(config("queue.connections.{$connection}.driver") === 'sync', 503, 'An asynchronous AI worker connection is required.');
        $proposal = app(ActionProposalService::class)->getOwned($principal, $id);
        if ((int) $proposal['version'] !== $version || $proposal['status'] !== 'approved') {
            throw ValidationException::withMessages(['proposal' => 'A current approved proposal is required.']);
        }
        app(ActionApprovalService::class)->assertApproved((object) $proposal);
        Bus::dispatch(new ExecuteApprovedAction($id, $version, $principal->actorId));
        return ['status' => 'queued', 'proposal_id' => $id, 'version' => $version];
    }
}
