<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Models\User;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Support\Str;

/**
 * Factory constructing authenticated, server-resolved AiPrincipal instances.
 */
class AiPrincipalFactory
{
    public function __construct(
        protected CompanyContextResolver $contextResolver,
        protected AiToolManifest $manifest,
        protected CommercialPermission $permissions,
    ) {}

    /**
     * Resolve an AiPrincipal for an authenticated user within explicit or session tenancy.
     */
    public function createForUser(
        int $userId,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $financialYearId = null,
        ?string $requestId = null
    ): AiPrincipal {
        $context = $this->contextResolver->resolve(
            userId: $userId,
            companyId: $companyId,
            branchId: $branchId,
            financialYearId: $financialYearId
        );

        $user = User::findOrFail($userId);

        // Discover permitted tools for actor
        $permittedTools = $this->resolvePermittedTools($user, $context);

        return new AiPrincipal(
            actorId: $userId,
            companyId: $context->companyId,
            branchId: $context->branchId,
            financialYearId: $context->financialYearId,
            permittedToolNames: $permittedTools,
            requestId: $requestId ?? (string) Str::uuid(),
            effectiveRole: $user->role_id ? (string) $user->role_id : null,
            isActive: (bool) ($user->is_active ?? true)
        );
    }

    /**
     * Determine which AI tools this user is permitted to invoke.
     *
     * @return array<string>
     */
    public function resolvePermittedTools(User $user, CompanyContext $context): array
    {
        if (!$this->permissions->allows('ai.use', $context, $user->id)) {
            return [];
        }

        $permitted = [];

        // 1. Receivables Tools (Outstanding, Invoices, Allocations)
        $receivablesPermissions = ['accounting.reports.view', 'customer-report', 'due-report', 'sales-index'];
        foreach (['receivables.outstanding.v1', 'receivables.invoices.v1', 'receivables.invoice_allocations.v1'] as $toolKey) {
            if ($this->manifest->has($toolKey)) {
                $handler = $this->manifest->get($toolKey);
                $required = $handler->requiredPermissions();
                $allowed = $this->permissions->allowed([...$required, ...$receivablesPermissions], $context, $user->id);

                if (array_diff($required, $allowed) === [] && array_intersect($receivablesPermissions, $allowed) !== []) {
                    $permitted[] = $handler->key();
                }
            }
        }

        // 2. Inventory Stock Summary Tool
        if ($this->manifest->has('inventory.stock_summary.v1')) {
            $handler = $this->manifest->get('inventory.stock_summary.v1');
            $required = $handler->requiredPermissions();
            $stockPermissions = ['warehouse-stock-report', 'product-report', 'products-index'];
            $allowed = $this->permissions->allowed([...$required, ...$stockPermissions], $context, $user->id);

            if (array_diff($required, $allowed) === [] && array_intersect($stockPermissions, $allowed) !== []) {
                $permitted[] = $handler->key();
            }
        }

        // 3. Sales Analytics Tool
        if ($this->manifest->has('sales.summary.v1')) {
            $handler = $this->manifest->get('sales.summary.v1');
            $required = $handler->requiredPermissions();
            $salesPermissions = ['sales-index', 'sale-report'];
            $allowed = $this->permissions->allowed([...$required, ...$salesPermissions], $context, $user->id);

            if (array_diff($required, $allowed) === [] && array_intersect($salesPermissions, $allowed) !== []) {
                $permitted[] = $handler->key();
            }
        }

        // 4. Payables Summary Tool
        if ($this->manifest->has('payables.summary.v1')) {
            $handler = $this->manifest->get('payables.summary.v1');
            $required = $handler->requiredPermissions();
            $payablesPermissions = ['accounting.reports.view', 'supplier-report', 'supplier-due-report', 'purchases-index'];
            $allowed = $this->permissions->allowed([...$required, ...$payablesPermissions], $context, $user->id);

            if (array_diff($required, $allowed) === [] && array_intersect($payablesPermissions, $allowed) !== []) {
                $permitted[] = $handler->key();
            }
        }

        // 5. Accounting Financial Statements Tool
        if ($this->manifest->has('accounting.statements.v1')) {
            $handler = $this->manifest->get('accounting.statements.v1');
            $required = $handler->requiredPermissions();
            $accountingPermissions = ['accounting.reports.view', 'financial-report', 'profit-loss-report', 'trial-balance-report'];
            $allowed = $this->permissions->allowed([...$required, ...$accountingPermissions], $context, $user->id);

            if (array_diff($required, $allowed) === [] && array_intersect($accountingPermissions, $allowed) !== []) {
                $permitted[] = $handler->key();
            }
        }

        // 6. GST Tax Summary Tool (P2)
        if ($this->manifest->has('tax.gst_summary.v1')) {
            $handler = $this->manifest->get('tax.gst_summary.v1');
            $required = $handler->requiredPermissions();
            $taxPermissions = ['accounting.reports.view', 'tax-report', 'gst-report', 'sales-index'];
            $allowed = $this->permissions->allowed([...$required, ...$taxPermissions], $context, $user->id);

            if (array_diff($required, $allowed) === [] && array_intersect($taxPermissions, $allowed) !== []) {
                $permitted[] = $handler->key();
            }
        }

        // 7. Manufacturing BOMs Tool (P2)
        if ($this->manifest->has('manufacturing.boms.v1')) {
            $handler = $this->manifest->get('manufacturing.boms.v1');
            $required = $handler->requiredPermissions();
            $mfgPermissions = ['operations.bom', 'manufacturing.bom', 'products-index', 'product-report'];
            $allowed = $this->permissions->allowed([...$required, ...$mfgPermissions], $context, $user->id);

            if (array_diff($required, $allowed) === [] && array_intersect($mfgPermissions, $allowed) !== []) {
                $permitted[] = $handler->key();
            }
        }

        // 8. Job Work Status Tool (P2)
        if ($this->manifest->has('jobwork.status.v1')) {
            $handler = $this->manifest->get('jobwork.status.v1');
            $required = $handler->requiredPermissions();
            $jwPermissions = ['operations.job_work', 'job_work.read', 'purchases-index', 'supplier-report'];
            $allowed = $this->permissions->allowed([...$required, ...$jwPermissions], $context, $user->id);

            if (array_diff($required, $allowed) === [] && array_intersect($jwPermissions, $allowed) !== []) {
                $permitted[] = $handler->key();
            }
        }

        // 9. Open Orders Tool (P2)
        if ($this->manifest->has('orders.open.v1')) {
            $handler = $this->manifest->get('orders.open.v1');
            $required = $handler->requiredPermissions();
            $orderPermissions = ['sales-index', 'purchases-index'];
            $allowed = $this->permissions->allowed([...$required, ...$orderPermissions], $context, $user->id);

            if (array_diff($required, $allowed) === [] && array_intersect($orderPermissions, $allowed) !== []) {
                $permitted[] = $handler->key();
            }
        }

        // 11. Document Preview Tool (P4)
        if ($this->manifest->has('documents.preview.v1')) {
            $handler = $this->manifest->get('documents.preview.v1');
            $required = $handler->requiredPermissions();
            $docPermissions = ['sales-index', 'purchases-index'];
            $allowed = $this->permissions->allowed([...$required, ...$docPermissions], $context, $user->id);

            if (array_diff($required, $allowed) === [] && array_intersect($docPermissions, $allowed) !== []) {
                $permitted[] = $handler->key();
            }
        }

        // 12. Grounded Company Knowledge Tool (P4 - available to authorized users)
        if ($permitted !== [] && $this->manifest->has('knowledge.query.v1')) {
            $permitted[] = 'knowledge.query.v1';
        }

        // 13. Copilot Help Tool (P2 - available to authorized AI users with at least one domain capability)
        if ($permitted !== [] && $this->manifest->has('copilot.help.v1')) {
            $permitted[] = 'copilot.help.v1';
        }

        return $permitted;
    }
}

