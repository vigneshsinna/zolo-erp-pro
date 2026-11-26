<?php

declare(strict_types=1);

namespace App\Ai\Billing\Services;

use App\Ai\Billing\Contracts\SubscriptionEntitlementResolverInterface;
use App\Ai\DTOs\AiPrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CreditAccountResolver
{
    public function __construct(
        protected ?SubscriptionEntitlementResolverInterface $entitlementResolver = null
    ) {
        $this->entitlementResolver ??= app(SubscriptionEntitlementResolverInterface::class);
    }

    /**
     * Resolve the authoritative credit account for the given principal or company ID.
     */
    public function resolve(AiPrincipal|int $principalOrCompanyId): stdClass
    {
        $companyId = $principalOrCompanyId instanceof AiPrincipal
            ? $principalOrCompanyId->companyId
            : $principalOrCompanyId;

        $mapping = DB::table('ai_credit_account_companies')
            ->join('ai_credit_accounts', 'ai_credit_account_companies.account_id', '=', 'ai_credit_accounts.id')
            ->where('ai_credit_account_companies.company_id', $companyId)
            ->select('ai_credit_accounts.*')
            ->first();

        if ($mapping) {
            return $mapping;
        }

        return DB::transaction(function () use ($companyId): stdClass {
            // Re-check inside transaction with lock
            $existing = DB::table('ai_credit_account_companies')
                ->join('ai_credit_accounts', 'ai_credit_account_companies.account_id', '=', 'ai_credit_accounts.id')
                ->where('ai_credit_account_companies.company_id', $companyId)
                ->select('ai_credit_accounts.*')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $accountId = (string) Str::uuid();
            $policy = config('ai_credits.credit_policy_version', 'cost_v1');
            $entitlement = $this->entitlementResolver->resolveForCompany($companyId);
            $requireSub = (bool) config('ai_credits.require_subscription', true);

            if ($requireSub && ! $entitlement->allowsAiUsage()) {
                $status = $entitlement->renewalStatus === 'expired' ? 'expired' : 'unsubscribed';
                $subId = $entitlement->subscriptionId;
            } else {
                $status = 'active';
                $subId = $entitlement->subscriptionId ?: 'sub_comp_' . $companyId;
            }

            DB::table('ai_credit_accounts')->insert([
                'id' => $accountId,
                'subscription_id' => $subId,
                'billing_entity_id' => 'entity_comp_' . $companyId,
                'currency' => 'INR',
                'credit_policy_version' => $policy,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ai_credit_account_companies')->insert([
                'account_id' => $accountId,
                'company_id' => $companyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('ai_credit_accounts')->where('id', $accountId)->first();
        });
    }

    /**
     * Verify that the company belongs to the specified credit account.
     */
    public function assertCompanyBelongsToAccount(string $accountId, int $companyId): void
    {
        $belongs = DB::table('ai_credit_account_companies')
            ->where('account_id', $accountId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $belongs) {
            throw new AccessDeniedHttpException("Company [{$companyId}] is not authorized to access credit account [{$accountId}].");
        }
    }

    /**
     * Map an additional company to share an existing credit account (pooled multi-company subscription).
     */
    public function attachCompany(string $accountId, int $companyId): void
    {
        DB::table('ai_credit_account_companies')->insertOrIgnore([
            'account_id' => $accountId,
            'company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
