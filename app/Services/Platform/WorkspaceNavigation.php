<?php

namespace App\Services\Platform;

use App\Services\Commercial\CommercialPermission;

/** One navigation contract for the workspace and API; route authorization remains authoritative. */
class WorkspaceNavigation
{
    public function items(CompanyContext $context, int $actor): array
    {
        $items = [];
        foreach ([
            ['Sales', '/sales?new=1', 'core.sales', 'sales-add', 'commercial.enabled'],
            ['Purchases', '/purchases?new=1', 'core.purchases', 'purchases-add', 'commercial.enabled'],
            ['Inventory', '/operations/stock', 'core.inventory', 'products-index', 'operations.enabled'],
            ['Accounts', '/accounting/vouchers', 'core.accounting', 'accounting.voucher.post', null],
            ['Manufacturing', '/operations/manufacturing', 'manufacturing.production', 'manufacturing.read', 'operations.enabled'],
            ['Job Work', '/operations/job-work', 'operations.job_work', 'job_work.read', 'operations.enabled'],
            ['Projects', '/operations/projects', 'operations.projects', 'projects.read', 'operations.enabled'],
            ['Reports', '/accounting/trial-balance', 'core.accounting', 'accounting.reports.view', null],
        ] as [$label, $url, $capability, $permission, $gate]) {
            if (($gate === null || config($gate)) && app(CapabilityService::class)->enabled($capability, $context)
                && app(CommercialPermission::class)->allows($permission, $context, $actor)) {
                $items[] = compact('label', 'url');
            }
        }
        if (app(CompanyContextResolver::class)->canManageFinancialYears($actor, $context->companyId)) {
            $items[] = ['label' => 'Settings', 'url' => '/workspace/setup?company_id='.$context->companyId];
        }
        return $items;
    }
}
