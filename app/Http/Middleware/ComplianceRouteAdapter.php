<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ComplianceController;
use App\Models\{Sale, Purchase};
use App\Services\Platform\CompanyContext;
use Closure;
use Illuminate\Http\Request;

/** Legacy entry points share the same context, permissions and posting services after cutover. */
class ComplianceRouteAdapter
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('compliance.enabled')) return $next($request);
        $response = app(RequireSharedCommercial::class)->handle($request, fn ($request) =>
            app(RequireCompliance::class)->handle($request, fn ($request) =>
                app(ResolveCompanyContext::class)->handle($request, function ($request) use ($next) {
                    $name = $request->route()->getActionName(); $action = $request->route()->getActionMethod();
                    $controller = app(ComplianceController::class);
                    if (str_contains($name, 'SaleController@genInvoice')) {
                        return $controller->printDocument($request, 'sale', (int) $request->route('id'));
                    }
                    if (str_contains($name, 'DamageStockController')) {
                        return $action === 'store' ? $controller->loss($request) : redirect('/compliance/setup');
                    }
                    if (str_contains($name, 'ExchangeController')) {
                        if ($action === 'store') {
                            $request->validate(['return_id' => 'required|integer|min:1']);
                            return $controller->exchange($request, $request->integer('return_id'));
                        }
                        return redirect()->route('sales.index', ['returns' => 1]);
                    }
                    $kind = str_contains($name, 'ReturnPurchaseController') ? 'purchase' : 'sale';
                    // /return-purchase and /return-sale stay inside their command center: that side only.
                    if ($action === 'index' || $action === 'returnData') return $controller->notes($request, $kind);
                    if (in_array($action, ['create', 'store'], true)) {
                        $context = $request->attributes->get(CompanyContext::class);
                        $source = ($kind === 'sale' ? Sale::class : Purchase::class)::visibleIn($context)
                            ->when($request->filled($kind.'_id'), fn ($q) => $q->whereKey($request->integer($kind.'_id')),
                                fn ($q) => $q->where('reference_no', $request->input('reference_no', '')))->firstOrFail();
                        return $action === 'create' ? $controller->returnForm($request, $kind, $source->id)
                            : $controller->createNote($request, $kind, $source->id);
                    }
                    abort(409, 'Use the shared return registry and document hub. Posted notes cannot be edited or deleted.');
                })));
        return \Illuminate\Routing\Router::toResponse($request, $response);
    }
}
