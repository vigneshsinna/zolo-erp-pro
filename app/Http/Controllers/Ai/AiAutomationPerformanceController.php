<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Scheduling\AiAutomationPerformanceService;
use App\Ai\Scheduling\AiAutomationReviewService;
use App\Http\Controllers\Controller;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AiAutomationPerformanceController extends Controller
{
    public function index(
        Request $request, CompanyContextResolver $resolver,
        AiAutomationPerformanceService $service,
    ): View {
        $days = $request->query('days', 30);
        if (!in_array((string) $days, ['7', '30', '90'], true)) {
            throw ValidationException::withMessages(['days' => 'Select 7, 30 or 90 days.']);
        }
        $context = $resolver->forActor(userId: (int) $request->user()->id);
        return view('backend.ai.automation_performance', [
            'metrics' => $service->report($context, (int) $request->user()->id, (int) $days),
        ]);
    }

    public function review(
        Request $request, CompanyContextResolver $resolver, AiAutomationReviewService $service,
    ): RedirectResponse {
        $context = $resolver->forActor(userId: (int) $request->user()->id);
        $input = $request->validate([
            'run_id' => ['required', 'uuid'],
            'outcome' => ['required', 'in:correct,incorrect'],
            'minutes_saved' => ['required', 'integer', 'between:0,240'],
            'hourly_rate_rupees' => ['required', 'integer', 'between:0,10000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'days' => ['required', 'integer', 'in:7,30,90'],
        ]);
        $days = (int) $input['days'];
        unset($input['days']);
        $service->record($context, (int) $request->user()->id, $input);
        return redirect()->route('ai.performance.index', ['days' => $days])
            ->with('message', 'The human review was recorded. Savings remain reviewer estimates, not verified accounting gains.');
    }
}
