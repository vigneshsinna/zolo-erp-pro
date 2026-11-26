<?php

declare(strict_types=1);

namespace App\Ai\Billing\Contracts;

use App\Ai\Billing\DTOs\SubscriptionEntitlement;
use Carbon\Carbon;

interface SubscriptionEntitlementResolverInterface
{
    /**
     * Resolve subscription entitlement and plan limits for the specified company.
     */
    public function resolveForCompany(int $companyId, ?Carbon $asOf = null): SubscriptionEntitlement;

    /**
     * Resolve subscription entitlement and plan limits for the specified billing account.
     */
    public function resolveForAccount(string $accountId, ?Carbon $asOf = null): SubscriptionEntitlement;
}
