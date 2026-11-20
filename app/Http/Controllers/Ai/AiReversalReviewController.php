<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Services\AiActionReversalRequestService;
use App\Http\Controllers\Controller;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AiReversalReviewController extends Controller
{
    public function index(
        Request $request,
        CompanyContextResolver $resolver,
        AiActionReversalRequestService $service,
    ): View {
        $context = $resolver->forActor(userId: (int) $request->user()->id);
        $requests = $service->list($context, (int) $request->user()->id);
        return view('backend.ai.reversals', ['requests' => $requests]);
    }

    public function store(
        Request $request,
        CompanyContextResolver $resolver,
        AiActionReversalRequestService $service,
    ): RedirectResponse {
        $input = $request->validate([
            'execution_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:12', 'max:500'],
        ]);
        $context = $resolver->forActor(userId: (int) $request->user()->id);
        $service->request($context, (int) $request->user()->id, $input['execution_id'], $input['reason']);
        return redirect()->route('ai.reversals.index')->with(
            'message', 'Manual reversal review requested. No ERP records were reversed.'
        );
    }
}
