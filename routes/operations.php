<?php

use App\Http\Controllers\OperationsController;
use App\Http\Middleware\RequireOperations;
use Illuminate\Support\Facades\Route;

$api = $operationsApi ?? false;
$middleware = $api
    ? ['auth:sanctum', 'company.context', RequireOperations::class]
    : ['auth', 'company.context', 'common', RequireOperations::class];

Route::prefix($api ? 'v1/operations' : 'operations')->name($api ? 'api.operations.' : 'operations.')
    ->middleware($middleware)->group(function () use ($api) {
        // Land on the first area this company has switched on (stock is always available).
        Route::get('/', function (\Illuminate\Http\Request $request) use ($api) {
            $context = $request->attributes->get(\App\Services\Platform\CompanyContext::class);
            $capabilities = app(\App\Services\Platform\CapabilityService::class);
            $area = collect(['job-work' => 'operations.job_work', 'projects' => 'operations.projects', 'manufacturing' => 'manufacturing.bom'])
                ->filter(fn ($capability) => $context && $capabilities->enabled($capability, $context))->keys()->first() ?? 'stock';
            return redirect()->route($api ? 'api.operations.hub' : 'operations.hub', ['area' => $area]);
        })->name('index');
        Route::get('{area}', [OperationsController::class, 'hub'])->where('area', 'manufacturing|job-work|profiles|projects|stock')->name('hub');
        Route::get('{kind}/{id}', [OperationsController::class, 'detail'])->where('kind', 'production|job-work|project')->whereNumber('id')->name('detail');
        Route::post('{action}', [OperationsController::class, 'action'])->where('action', 'bom|production-plan|job-order|project|expiry-writeoff');
        Route::post('{action}/{id}', [OperationsController::class, 'action'])->where('action', 'production-complete|production-reverse|job-dispatch|job-receive|job-return|job-rework|job-bill|job-rebill|job-reverse-dispatch|job-reverse-receipt|project-quotation|project-allocate|project-dispatch|project-install|project-commission|project-service|project-link')->whereNumber('id');
        Route::post('configure/{action}', [OperationsController::class, 'configure'])->where('action', 'profile|process|scheme');
        Route::match(['GET', 'POST'], 'product/{id}/attributes', [OperationsController::class, 'attributes'])->whereNumber('id');
        Route::get('inventory/fefo', [OperationsController::class, 'fefo']);
        Route::get('inventory/pieces', [OperationsController::class, 'pieces']);
        Route::get('inventory/lots', [OperationsController::class, 'lots']);
        Route::get('dispatch/{id}/print', [OperationsController::class, 'materialDocument'])->whereNumber('id');
        Route::get('receipt/{id}/print', [OperationsController::class, 'receiptDocument'])->whereNumber('id');
    });
