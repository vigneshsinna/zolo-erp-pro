<?php

use App\Http\Controllers\ComplianceController;
use App\Http\Middleware\{RequireCompliance, RequireSharedCommercial};
use Illuminate\Support\Facades\Route;

// Settings › GST: the one place for registration, rate slabs, HSN/SAC, product and party tax.
Route::prefix('settings/gst')->middleware(['auth', 'company.context', RequireCompliance::class, 'common'])->group(function () {
    Route::get('/', [\App\Http\Controllers\GstSettingsController::class, 'index'])->name('settings.gst');
    Route::post('{action}', [\App\Http\Controllers\GstSettingsController::class, 'save'])
        ->where('action', 'registration|install|category|category-delete|rename|rate|code|code-delete|products|parties|party|print|garment-band|trade')->name('settings.gst.save');
});

Route::prefix('compliance')->middleware(['auth', 'company.context', RequireSharedCommercial::class, RequireCompliance::class, 'common'])
    ->where(['kind' => 'sale|purchase', 'documentKind' => 'sale|purchase|sale_note|purchase_note|sales_order', 'id' => '[0-9]+'])->group(function () {
        Route::get('setup', [ComplianceController::class, 'setup']);
        Route::post('setup/{resource}', [ComplianceController::class, 'saveSetup']);
        Route::post('gst/lookup', [ComplianceController::class, 'lookup']);
        Route::get('gst/report', [ComplianceController::class, 'report']);
        Route::get('gst/export', [ComplianceController::class, 'export']);
        Route::get('gst/returns', [ComplianceController::class, 'gstReturns'])->name('compliance.gst-returns');
        Route::get('{format}/{source}/{id}', [ComplianceController::class, 'eDocument'])
            ->where(['format' => 'einvoice|ewaybill', 'source' => 'sale|dispatch'])->name('compliance.e-document');
        Route::get('returns', [ComplianceController::class, 'notes']);
        Route::get('{kind}/returnable', [ComplianceController::class, 'returnable']);
        Route::get('{kind}/{id}/return', [ComplianceController::class, 'returnForm']);
        Route::post('{kind}/{id}/notes', [ComplianceController::class, 'createNote']);
        Route::post('{kind}/notes/{id}/approve', [ComplianceController::class, 'approve']);
        Route::get('documents/{documentKind}/{id}', [ComplianceController::class, 'document']);
        Route::get('documents/{documentKind}/{id}/print', [ComplianceController::class, 'printDocument']);
        Route::post('documents/{documentKind}/{id}/dispatch', [ComplianceController::class, 'dispatch']);
        Route::post('dispatch/{id}/retry', [ComplianceController::class, 'retry']);
        Route::post('stock-loss', [ComplianceController::class, 'loss']);
        Route::post('exchange/{id}', [ComplianceController::class, 'exchange']);
    });
