<?php

declare(strict_types=1);

namespace App\Ai\Billing\Services;

use App\Ai\Billing\Contracts\SubscriptionEntitlementResolverInterface;
use App\Ai\Billing\DTOs\SubscriptionEntitlement;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionEntitlementResolver implements SubscriptionEntitlementResolverInterface
{
    /**
     * Resolve subscription entitlement and plan limits for the specified company.
     */
    public function resolveForCompany(int $companyId, ?Carbon $asOf = null): SubscriptionEntitlement
    {
        $now = $asOf ? $asOf->copy() : Carbon::now('UTC');

        // Escape hatch for testing or single-tenant setups with subscription enforcement disabled
        if (! (bool) config('ai_credits.require_subscription', true)) {
            $defaultCredits = (int) config('ai_credits.included_monthly_credits', 20000);
            $defaultPages = (int) config('ai_credits.monthly_media_pages', 100);
            $defaultConcurrency = (int) config('ai_credits.max_concurrent_runs', 5);

            return new SubscriptionEntitlement(
                isActive: true,
                subscriptionId: 'sub_comp_' . $companyId,
                planSku: 'default_unmetered',
                includedMonthlyCredits: $defaultCredits,
                monthlyMediaPages: $defaultPages,
                maxConcurrentRuns: $defaultConcurrency,
                renewalStatus: 'active',
                expiresAt: null,
                metadata: ['source' => 'unmetered_config']
            );
        }

        $company = Company::find($companyId);
        if (! $company || $company->status !== 'active') {
            return new SubscriptionEntitlement(
                isActive: false,
                subscriptionId: null,
                planSku: 'none',
                includedMonthlyCredits: 0,
                monthlyMediaPages: 0,
                maxConcurrentRuns: 0,
                renewalStatus: 'inactive',
                expiresAt: null,
                metadata: ['reason' => 'company_inactive_or_missing']
            );
        }

        // 1. Check company settings_json for explicit subscription/plan configuration
        $settings = is_array($company->settings_json) ? $company->settings_json : [];
        $sub = $settings['subscription'] ?? $settings['ai_subscription'] ?? null;

        if (is_array($sub)) {
            $subId = (string) ($sub['id'] ?? $sub['subscription_id'] ?? '');
            $planKey = (string) ($sub['plan_sku'] ?? $sub['plan'] ?? 'pro');
            $status = (string) ($sub['status'] ?? 'active');
            $renewalStatus = (string) ($sub['renewal_status'] ?? ($status === 'active' ? 'active' : 'unsubscribed'));
            $expiresAt = isset($sub['expires_at'])
                ? Carbon::parse($sub['expires_at'])
                : (isset($sub['expiry_date']) ? Carbon::parse($sub['expiry_date'])->endOfDay() : null);

            if ($expiresAt && $now->greaterThan($expiresAt)) {
                return new SubscriptionEntitlement(
                    isActive: false,
                    subscriptionId: $subId ?: null,
                    planSku: $planKey,
                    includedMonthlyCredits: 0,
                    monthlyMediaPages: 0,
                    maxConcurrentRuns: 0,
                    renewalStatus: 'expired',
                    expiresAt: $expiresAt,
                    metadata: ['source' => 'company_settings', 'expired' => true]
                );
            }

            if ($status !== 'active') {
                return new SubscriptionEntitlement(
                    isActive: false,
                    subscriptionId: $subId ?: null,
                    planSku: $planKey,
                    includedMonthlyCredits: 0,
                    monthlyMediaPages: 0,
                    maxConcurrentRuns: 0,
                    renewalStatus: $renewalStatus,
                    expiresAt: $expiresAt,
                    metadata: ['source' => 'company_settings', 'status' => $status]
                );
            }

            $plan = config("ai_credits.plans.{$planKey}") ?? config('ai_credits.plans.pro', []);
            $credits = (int) ($plan['included_monthly_credits'] ?? config('ai_credits.included_monthly_credits', 20000));
            $pages = (int) ($plan['monthly_media_pages'] ?? config('ai_credits.monthly_media_pages', 100));
            $concurrency = (int) ($plan['max_concurrent_runs'] ?? config('ai_credits.max_concurrent_runs', 5));

            return new SubscriptionEntitlement(
                isActive: true,
                subscriptionId: $subId ?: "sub_comp_{$companyId}",
                planSku: $planKey,
                includedMonthlyCredits: $credits,
                monthlyMediaPages: $pages,
                maxConcurrentRuns: $concurrency,
                renewalStatus: $renewalStatus,
                expiresAt: $expiresAt,
                metadata: ['source' => 'company_settings']
            );
        }

        // 2. Check general_settings table (legacy / multi-tenant package setup)
        try {
            $generalSetting = DB::table('general_settings')->first();
            if ($generalSetting && (! empty($generalSetting->package_id) || ! empty($generalSetting->subscription_type))) {
                $expiresAt = ! empty($generalSetting->expiry_date)
                    ? Carbon::parse($generalSetting->expiry_date)->endOfDay()
                    : null;

                if ($expiresAt && $now->greaterThan($expiresAt)) {
                    return new SubscriptionEntitlement(
                        isActive: false,
                        subscriptionId: 'sub_pkg_' . ($generalSetting->package_id ?? $companyId),
                        planSku: 'expired',
                        includedMonthlyCredits: 0,
                        monthlyMediaPages: 0,
                        maxConcurrentRuns: 0,
                        renewalStatus: 'expired',
                        expiresAt: $expiresAt,
                        metadata: ['source' => 'general_settings', 'expired' => true]
                    );
                }

                $subType = (string) ($generalSetting->subscription_type ?? 'monthly');
                $renewalStatus = match ($subType) {
                    'free_trial' => 'trial',
                    'prepaid' => 'prepaid',
                    default => 'active'
                };
                $planKey = match ($subType) {
                    'free_trial' => 'free_trial',
                    default => 'pro'
                };

                $plan = config("ai_credits.plans.{$planKey}") ?? config('ai_credits.plans.pro', []);
                $credits = (int) ($plan['included_monthly_credits'] ?? config('ai_credits.included_monthly_credits', 20000));
                $pages = (int) ($plan['monthly_media_pages'] ?? config('ai_credits.monthly_media_pages', 100));
                $concurrency = (int) ($plan['max_concurrent_runs'] ?? config('ai_credits.max_concurrent_runs', 5));

                return new SubscriptionEntitlement(
                    isActive: true,
                    subscriptionId: 'sub_gen_' . ($generalSetting->package_id ?? $companyId),
                    planSku: $planKey,
                    includedMonthlyCredits: $credits,
                    monthlyMediaPages: $pages,
                    maxConcurrentRuns: $concurrency,
                    renewalStatus: $renewalStatus,
                    expiresAt: $expiresAt,
                    metadata: ['source' => 'general_settings']
                );
            }
        } catch (\Throwable) {
            // In testing or minimal schemas where general_settings is not present
        }

        // 3. Check existing verified subscription on credit account
        try {
            $account = DB::table('ai_credit_account_companies')
                ->join('ai_credit_accounts', 'ai_credit_account_companies.account_id', '=', 'ai_credit_accounts.id')
                ->where('ai_credit_account_companies.company_id', $companyId)
                ->select('ai_credit_accounts.*')
                ->first();

            if ($account && ! empty($account->subscription_id) && ! str_starts_with($account->subscription_id, 'sub_comp_') && $account->status === 'active') {
                $plan = config('ai_credits.plans.pro', []);

                return new SubscriptionEntitlement(
                    isActive: true,
                    subscriptionId: $account->subscription_id,
                    planSku: 'pro',
                    includedMonthlyCredits: (int) ($plan['included_monthly_credits'] ?? 20000),
                    monthlyMediaPages: (int) ($plan['monthly_media_pages'] ?? 100),
                    maxConcurrentRuns: (int) ($plan['max_concurrent_runs'] ?? 5),
                    renewalStatus: 'active',
                    expiresAt: null,
                    metadata: ['source' => 'account_record']
                );
            }
        } catch (\Throwable) {
            // Table may not exist yet in early migration stages
        }

        // 4. Default: No verified subscription found
        return new SubscriptionEntitlement(
            isActive: false,
            subscriptionId: null,
            planSku: 'none',
            includedMonthlyCredits: 0,
            monthlyMediaPages: 0,
            maxConcurrentRuns: 0,
            renewalStatus: 'unsubscribed',
            expiresAt: null,
            metadata: ['reason' => 'no_subscription_found']
        );
    }

    /**
     * Resolve subscription entitlement and plan limits for the specified billing account.
     */
    public function resolveForAccount(string $accountId, ?Carbon $asOf = null): SubscriptionEntitlement
    {
        $mapping = DB::table('ai_credit_account_companies')
            ->where('account_id', $accountId)
            ->first();

        if ($mapping) {
            return $this->resolveForCompany((int) $mapping->company_id, $asOf);
        }

        $account = DB::table('ai_credit_accounts')->where('id', $accountId)->first();
        if (! $account) {
            return new SubscriptionEntitlement(
                isActive: false,
                subscriptionId: null,
                planSku: 'none',
                includedMonthlyCredits: 0,
                monthlyMediaPages: 0,
                maxConcurrentRuns: 0,
                renewalStatus: 'unsubscribed'
            );
        }

        if ($account->status === 'active' && ! empty($account->subscription_id) && ! str_starts_with($account->subscription_id, 'sub_comp_')) {
            $plan = config('ai_credits.plans.pro', []);

            return new SubscriptionEntitlement(
                isActive: true,
                subscriptionId: $account->subscription_id,
                planSku: 'pro',
                includedMonthlyCredits: (int) ($plan['included_monthly_credits'] ?? 20000),
                monthlyMediaPages: (int) ($plan['monthly_media_pages'] ?? 100),
                maxConcurrentRuns: (int) ($plan['max_concurrent_runs'] ?? 5),
                renewalStatus: 'active'
            );
        }

        return new SubscriptionEntitlement(
            isActive: false,
            subscriptionId: $account->subscription_id,
            planSku: 'none',
            includedMonthlyCredits: 0,
            monthlyMediaPages: 0,
            maxConcurrentRuns: 0,
            renewalStatus: $account->status === 'expired' ? 'expired' : 'unsubscribed'
        );
    }
}
