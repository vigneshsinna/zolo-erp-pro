<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Platform\LicenseService;
use Illuminate\Console\Command;

/**
 * Inspect or manage a tenant company's SaaS license from CLI.
 */
class ManageLicense extends Command
{
    protected $signature = 'erp:license {company : Company ID or code}
        {--plan= : Plan code to assign (trial, starter, growth, industry_pro, enterprise)}
        {--renew-days= : Extend expiry by N days}
        {--suspend= : Suspend license with reason}
        {--activate : Reactivate suspended license}';

    protected $description = 'Inspect or update a tenant company SaaS license';

    public function handle(LicenseService $licenses): int
    {
        $arg = (string) $this->argument('company');
        $company = is_numeric($arg)
            ? Company::find((int) $arg)
            : Company::where('code', strtoupper($arg))->first();

        if (!$company) {
            $this->error("Company '{$arg}' not found.");
            return self::FAILURE;
        }

        if ($plan = $this->option('plan')) {
            $licenses->assignPlan($company->id, (string) $plan, [], 1);
            $this->info("Assigned plan '{$plan}' to {$company->legal_name}.");
        }
        if ($days = $this->option('renew-days')) {
            $licenses->updateLifecycle($company->id, 'renew', ['days' => (int) $days], 1);
            $this->info("Renewed {$company->legal_name} by {$days} days.");
        }
        if ($reason = $this->option('suspend')) {
            $licenses->updateLifecycle($company->id, 'suspend', ['reason' => (string) $reason], 1);
            $this->warn("Suspended {$company->legal_name}: {$reason}");
        }
        if ($this->option('activate')) {
            $licenses->updateLifecycle($company->id, 'activate', [], 1);
            $this->info("Activated {$company->legal_name}.");
        }

        $snap = $licenses->snapshot($company->id);
        $usage = $licenses->quotaUsage($company->id);
        $ai = $licenses->aiEntitlement($company->id);
        $licensed = count(array_filter($snap['modules'], fn ($m) => !empty($m['licensed'])));

        $this->line("Company: {$company->legal_name} (#{$company->id}, {$company->code})");
        $this->line('Status: '.$snap['status'].' | Plan: '.($snap['plan']['name'] ?? 'Legacy/Custom').' | Key: '.($snap['license_key'] ?? 'none'));
        $this->line('Expires: '.($snap['expires_at'] ?? 'never').' | Grace until: '.($snap['grace_ends_at'] ?? 'none'));
        $this->line("Modules licensed: {$licensed}/".count($snap['modules']));
        $this->line(sprintf(
            'Usage: users %d/%s | branches %d/%s | warehouses %d/%s | monthly invoices %d/%s',
            $usage['users'], $snap['limits']['users'] ?? '∞',
            $usage['branches'], $snap['limits']['branches'] ?? '∞',
            $usage['warehouses'], $snap['limits']['warehouses'] ?? '∞',
            $usage['monthly_invoices'], $snap['limits']['monthly_invoices'] ?? '∞',
        ));
        $this->line(sprintf(
            'AI: %s | Mode: %s | Credits: %d/%s | Media Pages: %d/%s | Concurrent Runs: %s | Daily Tokens: %s',
            $ai['is_active'] ? 'enabled' : 'locked',
            $ai['read_only'] ? 'read-only' : 'read+write',
            $usage['ai_credits_used'] ?? 0, $ai['included_monthly_credits'] ?? '∞',
            $usage['ai_media_pages_used'] ?? 0, $ai['monthly_media_pages'] ?? '∞',
            $ai['max_concurrent_runs'] ?? '∞',
            $ai['daily_token_limit'] ?? '∞',
        ));

        return self::SUCCESS;
    }
}
