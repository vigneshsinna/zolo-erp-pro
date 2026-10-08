<?php

namespace App\Http\Middleware;

use App\Http\Controllers\CommercialController;
use Closure;
use Illuminate\Http\Request;

/** Routes legacy web/POS mutations through the shared engine. The normal Sales/Purchase pages are the only entry UI. */
class CommercialRouteAdapter
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('commercial.enabled')) {
            return $next($request);
        }
        $action = $request->route()->getActionMethod();
        if ($request->isMethod('GET') && !in_array($action, ['paypalSuccess', 'paypalPaymentSuccess', 'stripePayment', 'duplicate'], true)) {
            return $next($request);
        }
        if (in_array($action, ['saleData', 'purchaseData'], true)) {
            return $next($request);
        }
        $kind = str_contains($request->route()->getActionName(), 'SaleController') ? 'sale' : 'purchase';
        if ($action === 'addPayment' && !$request->filled('paying_method')) {
            $method = match ((string) $request->input('paid_by_id')) {
                '1' => 'Cash', '3' => 'Credit Card', '4' => 'Cheque', 'bank', 'Bank' => 'Bank',
                default => abort(422, 'This tender requires its reviewed company-owned settlement workflow.'),
            };
            $request->merge(['paying_method' => $method]);
        }
        if ($action === 'addPayment' && $request->filled('payment_at')) {
            $request->merge(['payment_at' => substr(normalize_to_sql_datetime($request->payment_at), 0, 10)]);
        }
        if ($action === 'deleteBySelection') {
            $request->merge(['ids' => $request->input('ids', $request->input($kind === 'sale' ? 'saleIdArray' : 'purchaseIdArray'))]);
        }
        return app(RequireSharedCommercial::class)->handle($request, fn ($request) =>
            app(ResolveCompanyContext::class)->handle($request, function ($request) use ($kind, $action) {
                $controller = app(CommercialController::class);
                return match ($action) {
                    'store' => $controller->store($request, $kind, legacy: true),
                    'update' => $controller->replace($request, $kind, (int) $request->route('sale', $request->route('purchase', $request->route('id')))),
                    'destroy' => $controller->reverse($request, $kind, (int) $request->route('sale', $request->route('purchase', $request->route('id')))),
                    'deleteBySelection' => $controller->reverseSelection($request, $kind),
                    'addPayment' => $controller->payment($request, $kind, (int) $request->input($kind.'_id')),
                    default => abort(409, 'This legacy mutation requires its reviewed shared commercial adapter.'),
                };
            }));
    }
}
