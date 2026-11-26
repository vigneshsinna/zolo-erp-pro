<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Services\Platform\CapabilityService;
use App\Services\Platform\LegacyModuleAdapter;
use App\Services\Platform\LicenseService;

class AddonApiController extends BaseApiController
{
    /**
     * Get active addons and enabled features for current tenant / instance
     */
    public function status(Request $request, CapabilityService $capabilities, LicenseService $licenses): JsonResponse
    {
        $context = $this->companyContext($request);
        $company = DB::table('companies')->where('id', $context->companyId)->first();
        $activeModules = array_keys(array_filter(LegacyModuleAdapter::MODULES,
            fn ($key) => $capabilities->enabled($key, $context)));

        $features = [
            'water_logistics' => in_array('water_logistics', $activeModules),
            'cafe_bakery' => in_array('cafe_bakery', $activeModules),
            'repair' => in_array('repair', $activeModules),
            'project_management' => in_array('project_management', $activeModules),
            'manufacturing' => in_array('manufacturing', $activeModules),
            'installment_plans' => in_array('installment_plans', $activeModules),
            'catalogue_qr' => in_array('catalogue_qr', $activeModules),
            'damage_stock' => in_array('damage_stock', $activeModules),
            'exchange' => in_array('exchange', $activeModules),
            'ecommerce' => in_array('ecommerce', $activeModules),
            'woocommerce' => in_array('woocommerce', $activeModules),
            'api' => in_array('api', $activeModules),
        ];

        $license = $licenses->snapshot($context->companyId);

        return $this->sendResponse([
            'system' => 'zoloERP SaaS Engine',
            'company_name' => $company->trade_name ?: $company->legal_name,
            'currency' => $company->base_currency_id,
            'license' => [
                'status' => $license['status'],
                'plan' => $license['plan'],
                'expires_at' => $license['expires_at'],
                'limits' => $license['limits'],
            ],
            'active_modules' => array_values($activeModules),
            'features' => $features,
            'capabilities' => $capabilities->forNavigation($context),
        ], 'Active addons and tenant features retrieved successfully');
    }
}
