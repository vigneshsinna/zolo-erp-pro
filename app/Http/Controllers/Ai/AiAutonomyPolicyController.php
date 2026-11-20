<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Services\AutonomyPolicyService;
use App\Http\Controllers\Controller;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AiAutonomyPolicyController extends Controller
{
    public function index(Request $request, CompanyContextResolver $resolver, AutonomyPolicyService $policies): View
    {
        $actorId = (int) $request->user()->id;
        $context = $resolver->forActor(userId: $actorId);
        $policies->assertAdministrator($context, $actorId);
        return view('backend.ai.autonomy_policies', [
            'definitions' => $policies->definitions(),
            'stored' => $policies->stored($context->companyId),
        ]);
    }

    public function store(
        Request $request,
        string $actionKey,
        CompanyContextResolver $resolver,
        AutonomyPolicyService $policies,
    ): RedirectResponse {
        $actorId = (int) $request->user()->id;
        $context = $resolver->forActor(userId: $actorId);
        $validated = $request->validate([
            'requested_level' => ['required', 'in:suggest,draft,auto_under_limits,fully_auto'],
            'max_amount_rupees' => ['required', 'integer', 'min:0', 'max:10000000'],
            'daily_action_limit' => ['required', 'integer', 'min:1', 'max:100'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ]);
        $policies->save($context, $actorId, $actionKey, $validated);
        return redirect()->route('ai.autonomy.index')->with('message', 'Policy preference saved. Human approval remains mandatory.');
    }
}
