<?php

use App\Http\Controllers\InventoryLotReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'company.context', 'common'])->group(function () {
    Route::get('inventory-reports/stock-by-lot', [InventoryLotReportController::class, 'index'])
        ->name('inventory-reports.stock-by-lot');
    Route::get('inventory-reports/lifecycle', [InventoryLotReportController::class, 'lifecycle'])
        ->name('inventory-reports.lifecycle');
    Route::get('inventory-reports/timeline', [InventoryLotReportController::class, 'timeline'])
        ->name('inventory-reports.timeline');
});
