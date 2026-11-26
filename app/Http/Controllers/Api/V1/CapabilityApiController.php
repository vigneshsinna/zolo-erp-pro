<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Platform\CapabilityService;
use App\Services\Platform\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CapabilityApiController extends BaseApiController
{
    public function license(Request $request, LicenseService $licenses)
    {
        $companyId = $this->companyContext($request)->companyId;

        return $this->sendResponse([
            'license' => $licenses->snapshot($companyId),
            'usage' => $licenses->quotaUsage($companyId),
            'ai' => $licenses->aiEntitlement($companyId),
        ]);
    }

    public function index(Request $request, CapabilityService $service)
    {
        return $this->sendResponse([
            'capabilities' => $service->snapshot($this->companyContext($request)->companyId),
            'profiles' => DB::table('business_profiles')->get(['key', 'name', 'description']),
        ]);
    }

    public function update(Request $request, string $key, CapabilityService $service)
    {
        $data = $request->validate(['enabled' => 'required|boolean', 'config' => 'sometimes|array']);
        $context = $this->companyContext($request);
        if ($data['enabled']) {
            $service->enable($key, $data['config'] ?? [], $context, $request->user()->id);
        } else {
            $service->disable($key, $context, $request->user()->id);
        }
        return $this->sendResponse($service->snapshot($context->companyId));
    }

    public function profile(Request $request, string $key, CapabilityService $service)
    {
        $context = $this->companyContext($request);
        $service->applyProfile($key, $context, $request->user()->id);
        return $this->sendResponse($service->snapshot($context->companyId));
    }

    public function import(Request $request, CapabilityService $service)
    {
        $context = $this->companyContext($request);
        $service->importLegacy($context, $request->user()->id);
        return $this->sendResponse($service->snapshot($context->companyId));
    }
}
