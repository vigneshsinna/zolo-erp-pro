<?php

use App\Ai\Scheduling\ScheduledRuleController;
use Illuminate\Support\Facades\Route;

// Require once outside route groups. Session routes retain CSRF protection.
foreach ([['prefix' => 'ai/schedules', 'middleware' => ['web', 'auth', 'company.context'], 'name' => 'ai.schedules.'],
    ['prefix' => 'api/v1/ai/schedules', 'middleware' => ['api', 'auth:sanctum', 'company.context'], 'name' => 'api.v1.ai.schedules.']] as $group) {
    Route::prefix($group['prefix'])->middleware([...$group['middleware'], 'throttle:30,1'])->name($group['name'])->group(function (): void {
        Route::get('/', [ScheduledRuleController::class, 'index'])->name('index');
        Route::post('/', [ScheduledRuleController::class, 'store'])->name('store');
        Route::post('{id}/cancel', [ScheduledRuleController::class, 'cancel'])->whereUuid('id')->name('cancel');
        Route::get('{id}/runs', [ScheduledRuleController::class, 'runs'])->whereUuid('id')->name('runs');
    });
}
