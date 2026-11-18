<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Adapters\CommercialDecimal;
use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\BatchResolutionResult;
use App\Ai\DTOs\EvaluationResult;
use App\Ai\DTOs\FieldIssue;
use App\Ai\DTOs\ResolutionOption;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;

final class InputCompletenessEvaluator
{
    private CommercialPermission $permissions;

    public function __construct(?CommercialPermission $permissions = null)
    {
        $this->permissions = $permissions ?? app(CommercialPermission::class);
    }
    /**
     * Deterministically evaluates completeness, validity, ambiguity, and dependencies across all requirements.
     * Never returns on first error; evaluates all fields and lines in a single pass.
     *
     * @param array<string, mixed> $slots
     */
    public function evaluate(
        ActionRequirementsProviderInterface $provider,
        WorkflowTaskContext $taskContext,
        array $slots,
        BatchResolutionResult $batchResult,
    ): EvaluationResult {
        $issues = [];
        $confirmed = [];
        $pendingChecks = [];
        $cleanSlots = [];

        $requirements = $provider->requirements($taskContext, $slots);

        foreach ($requirements as $req) {
            if ($req->isList) {
                $this->evaluateListRequirement($req, $taskContext, $slots, $batchResult, $issues, $confirmed, $cleanSlots, $provider);
                continue;
            }

            $this->evaluateScalarRequirement($req, $taskContext, array_replace($slots, $cleanSlots), $batchResult, $issues, $confirmed, $cleanSlots, $provider->actionKey());
        }

        // Add downstream pending checks when prerequisite entities are unresolved
        $hasSupplierIssue = collect($issues)->contains(fn(FieldIssue $i) => $i->field === 'supplier_id');
        if ($hasSupplierIssue) {
            $pendingChecks[] = 'Authoritative GST determination and party tax calculation';
        }

        $hasCustomerIssue = collect($issues)->contains(fn(FieldIssue $i) => $i->field === 'customer_id');
        if ($hasCustomerIssue) {
            $pendingChecks[] = 'Customer credit limit check and GST place-of-supply determination';
        }

        $hasProductIssue = collect($issues)->contains(fn(FieldIssue $i) => str_contains($i->field, 'product_id') || str_contains($i->field, 'qty'));
        if ($hasProductIssue) {
            $pendingChecks[] = 'Commercial order line pricing and grand total reconciliation';
        }

        // If no blocking entity or format issues exist, run authoritative domain schema validation
        if (empty($issues)) {
            $validationErrors = $provider->validate($taskContext, $cleanSlots);
            foreach ($validationErrors as $field => $message) {
                $humanLabel = ucwords(str_replace(['.', '_'], ' ', $field));
                $humanReason = $message;

                if (preg_match('/^items\.(\d+)\.net_unit_cost$/', $field, $m)) {
                    $lineNum = ((int) $m[1]) + 1;
                    $humanLabel = "Item {$lineNum} Rate";
                    $humanReason = "Please confirm the purchase rate for this item.";
                } elseif (preg_match('/^items\.(\d+)\.qty$/', $field, $m)) {
                    $lineNum = ((int) $m[1]) + 1;
                    $humanLabel = "Item {$lineNum} Quantity";
                } elseif (preg_match('/^items\.(\d+)\.product_id$/', $field, $m)) {
                    $lineNum = ((int) $m[1]) + 1;
                    $humanLabel = "Item {$lineNum} Product";
                }

                $issues[] = new FieldIssue(
                    field: $field,
                    label: $humanLabel,
                    status: 'invalid',
                    reason: $humanReason,
                );
            }
        }

        return new EvaluationResult(
            issues: $issues,
            confirmedFields: $confirmed,
            pendingChecks: $pendingChecks,
            validatedSlots: $cleanSlots,
        );
    }

    private function evaluateScalarRequirement(
        ActionFieldRequirement $req,
        WorkflowTaskContext $taskContext,
        array $slots,
        BatchResolutionResult $batchResult,
        array &$issues,
        array &$confirmed,
        array &$cleanSlots,
        string $actionKey,
    ): void {
        $field = $req->fieldPath;
        $resolvedId = $batchResult->getResolvedId($field);
        $entity = $batchResult->getEntity($field);
        $resolution = $batchResult->getResolution($field);

        if ($field === 'warehouse_id' && $actionKey === 'purchase_order.create.v1'
            && config('erp_ai.conversation_enabled', false)
            && !isset($slots['warehouse_id'])
            && trim((string) ($slots['warehouse_query'] ?? '')) === '') {
            // A single branch warehouse is a useful candidate, not a user's
            // authorization to select the destination for this purchase order.
            $resolvedId = null;
            $resolution = null;
        }

        if ($resolvedId !== null) {
            $displayName = $entity && isset($entity->name) ? (string) $entity->name : (string) $resolvedId;
            $confirmed[] = [
                'field' => $field,
                'label' => $req->label,
                'display' => $displayName,
                'source' => isset($slots[$field]) ? 'user' : 'resolved',
            ];
            $cleanSlots[$field] = $resolvedId;
            return;
        }

        if ($resolution !== null) {
            $triad = $req->entityType !== null
                ? $this->buildTriadOptions($req->entityType, $field, $req->label, $resolution['query'] ?? '', $taskContext, $resolution['candidates'] ?? [])
                : [];

            if ($resolution['status'] === 'candidates') {
                $query = $resolution['query'] ?? '';
                $reason = $field === 'supplier_id'
                    ? "Multiple suppliers matched '{$query}'. Please select the intended {$req->label}."
                    : "Multiple matches found for '{$query}'. Please select the intended {$req->label}.";
                $issues[] = new FieldIssue(
                    field: $field,
                    label: $req->label,
                    status: 'ambiguous',
                    reason: $reason,
                    control: $req->inputControl,
                    candidates: $resolution['candidates'] ?? [],
                    resolutionOptions: $triad,
                    entityType: $req->entityType,
                );
                return;
            }

            if ($resolution['status'] === 'none') {
                $query = $resolution['query'] ?? '';
                $issues[] = new FieldIssue(
                    field: $field,
                    label: $req->label,
                    status: 'missing',
                    reason: "No match found for '{$query}'. Please select an existing {$req->label} or check spelling.",
                    control: $req->inputControl,
                    candidates: [],
                    resolutionOptions: $triad,
                    entityType: $req->entityType,
                );
                return;
            }
        }

        // Check if a direct slot value was provided for non-entity fields (e.g. date, note, order_discount)
        if (isset($slots[$field]) && $slots[$field] !== '' && $slots[$field] !== null) {
            $confirmed[] = [
                'field' => $field,
                'label' => $req->label,
                'display' => is_array($slots[$field]) ? json_encode($slots[$field], JSON_THROW_ON_ERROR) : (string) $slots[$field],
                'source' => 'user',
            ];
            $cleanSlots[$field] = $slots[$field];
            return;
        }

        // Check default policy
        $default = $req->resolveDefault($taskContext, $slots);
        if ($default !== null) {
            $displayVal = (string) $default;
            if ($req->entityType === 'warehouse' && is_numeric($default)) {
                $wh = Warehouse::where('company_id', $taskContext->companyId())->find($default);
                if ($wh) {
                    $displayVal = $wh->name;
                    $cleanSlots['warehouse_name'] = $wh->name;
                }
            }
            $confirmed[] = [
                'field' => $field,
                'label' => $req->label,
                'display' => $displayVal,
                'source' => 'erp_default',
            ];
            $cleanSlots[$field] = $default;
            return;
        }

        // If required and still empty, report missing
        if ($req->isRequired($slots, $taskContext)) {
            $triad = $req->entityType !== null
                ? $this->buildTriadOptions($req->entityType, $field, $req->label, '', $taskContext)
                : [];

            $issues[] = new FieldIssue(
                field: $field,
                label: $req->label,
                status: 'missing',
                reason: "{$req->label} is required.",
                control: $req->inputControl,
                resolutionOptions: $triad,
                entityType: $req->entityType,
            );
        }
    }

    private function evaluateListRequirement(
        ActionFieldRequirement $req,
        WorkflowTaskContext $taskContext,
        array $slots,
        BatchResolutionResult $batchResult,
        array &$issues,
        array &$confirmed,
        array &$cleanSlots,
        ?ActionRequirementsProviderInterface $provider = null,
    ): void {
        $rawItems = (array) ($slots[$req->fieldPath] ?? []);

        if (empty($rawItems)) {
            if ($req->isRequired($slots, $taskContext)) {
                $issues[] = new FieldIssue(
                    field: $req->fieldPath,
                    label: $req->label,
                    status: 'missing',
                    reason: 'At least one line item is required.',
                    control: 'line_items',
                    entityType: 'product',
                );
            }
            return;
        }

        $cleanLines = [];

        foreach ($rawItems as $index => $line) {
            $clientLineId = (string) ($line['client_line_id'] ?? "line-{$index}");
            $lineIndex = $index + 1;
            $cleanLine = [
                'client_line_id' => $clientLineId,
            ];

            // 1. Evaluate Product ID for this line
            $productFieldKey = "items[{$clientLineId}].product_id";
            $resolvedProductId = $batchResult->getResolvedId($productFieldKey);
            $productEntity = $batchResult->getEntity($productFieldKey);
            $productResolution = $batchResult->getResolution($productFieldKey);

            if ($resolvedProductId !== null) {
                $pName = $productEntity && isset($productEntity->name) ? (string) $productEntity->name : "Product #{$resolvedProductId}";
                $pCode = $productEntity && isset($productEntity->code) ? " (SKU: {$productEntity->code})" : '';
                $confirmed[] = [
                    'field' => $productFieldKey,
                    'label' => "Line {$lineIndex} Product",
                    'display' => "{$pName}{$pCode}",
                    'source' => isset($line['product_id']) ? 'user' : 'resolved',
                ];
                $cleanLine['product_id'] = (int) $resolvedProductId;
            } else {
                $query = $productResolution['query'] ?? ($line['product_query'] ?? '');
                $productTriad = $this->buildTriadOptions(
                    'product',
                    $productFieldKey,
                    "Line {$lineIndex} Product",
                    $query,
                    $taskContext,
                    $productResolution['candidates'] ?? [],
                    ['cost_price' => isset($line['net_unit_cost']) ? (float) $line['net_unit_cost'] : null]
                );

                if ($productResolution !== null && $productResolution['status'] === 'candidates') {
                    $issues[] = new FieldIssue(
                        field: $productFieldKey,
                        label: "Line {$lineIndex} Product",
                        status: 'ambiguous',
                        reason: "Multiple products match '{$query}'. Select the specific SKU.",
                        control: 'entity_select',
                        candidates: $productResolution['candidates'] ?? [],
                        clientLineId: $clientLineId,
                        resolutionOptions: $productTriad,
                        entityType: 'product',
                    );
                } elseif ($productResolution !== null && $productResolution['status'] === 'none') {
                    $issues[] = new FieldIssue(
                        field: $productFieldKey,
                        label: "Line {$lineIndex} Product",
                        status: 'missing',
                        reason: "Could not find any product matching '{$query}'.",
                        control: 'entity_select',
                        clientLineId: $clientLineId,
                        resolutionOptions: $productTriad,
                        entityType: 'product',
                    );
                } else {
                    $issues[] = new FieldIssue(
                        field: $productFieldKey,
                        label: "Line {$lineIndex} Product",
                        status: 'missing',
                        reason: "Product is required for line {$lineIndex}.",
                        control: 'entity_select',
                        clientLineId: $clientLineId,
                        resolutionOptions: $productTriad,
                        entityType: 'product',
                    );
                }
            }

            // 2. Evaluate Quantity for this line
            $qtyFieldKey = "items[{$clientLineId}].qty";
            if (isset($line['qty']) && is_numeric($line['qty']) && (float) $line['qty'] > 0) {
                $qtyVal = (float) $line['qty'];
                $confirmed[] = [
                    'field' => $qtyFieldKey,
                    'label' => "Line {$lineIndex} Quantity",
                    'display' => CommercialDecimal::format($qtyVal).' units',
                    'source' => 'user',
                ];
                $cleanLine['qty'] = CommercialDecimal::format($qtyVal);
            } else {
                $issues[] = new FieldIssue(
                    field: $qtyFieldKey,
                    label: "Line {$lineIndex} Quantity",
                    status: isset($line['qty']) ? 'invalid' : 'missing',
                    reason: 'Quantity must be a positive number.',
                    control: 'number',
                    clientLineId: $clientLineId,
                );
            }

            // 3. Evaluate Unit Price / Cost for this line
            $actionKey = $provider !== null ? $provider->actionKey() : '';
            $isPurchase = str_contains($actionKey, 'purchase')
                || str_contains($actionKey, 'grn')
                || str_contains($actionKey, 'bill')
                || isset($slots['supplier_id'])
                || isset($slots['supplier_query']);
            $isSale = !$isPurchase && (
                str_contains($actionKey, 'sale')
                || isset($slots['customer_id'])
                || isset($slots['customer_query'])
            );

            if ($isPurchase) {
                $costFieldKey = "items[{$clientLineId}].net_unit_cost";
                $userCost = $line['net_unit_cost'] ?? $line['net_unit_price'] ?? $line['rate'] ?? $line['unit_price'] ?? $line['unit_cost'] ?? null;
                if ($userCost !== null && is_numeric($userCost) && (float) $userCost >= 0) {
                    $costVal = (float) $userCost;
                    $confirmed[] = [
                        'field' => $costFieldKey,
                        'label' => "Line {$lineIndex} Rate",
                        'display' => '₹'.CommercialDecimal::format($costVal),
                        'source' => 'user',
                    ];
                    $cleanLine['net_unit_cost'] = CommercialDecimal::format($costVal);
                } elseif ($provider?->actionKey() === 'purchase_order.create.v1'
                    && config('erp_ai.conversation_enabled', false)) {
                    // Product master cost/price are reference values. They are not
                    // agreement to a Purchase Order rate and must not be silently used.
                    $reference = ($productEntity && is_numeric($productEntity->cost ?? null) && (float) $productEntity->cost > 0)
                        ? ' Product master cost is ₹'.CommercialDecimal::format((float) $productEntity->cost).'.'
                        : '';
                    $issues[] = new FieldIssue(
                        field: $costFieldKey,
                        label: "Line {$lineIndex} Rate",
                        status: 'missing',
                        reason: "Confirm the purchase rate for line {$lineIndex}.{$reference} Say 'rate 600' or choose another verified rate.",
                        control: 'number',
                        clientLineId: $clientLineId,
                    );
                } elseif ($productEntity && isset($productEntity->cost) && (float) $productEntity->cost > 0) {
                    $cleanLine['net_unit_cost'] = CommercialDecimal::format((float) $productEntity->cost);
                    $confirmed[] = [
                        'field' => $costFieldKey,
                        'label' => "Line {$lineIndex} Rate",
                        'display' => sprintf('₹%.2f (Master Cost)', (float) $productEntity->cost),
                        'source' => 'erp_default',
                    ];
                } elseif ($productEntity && isset($productEntity->price) && (float) $productEntity->price > 0) {
                    $cleanLine['net_unit_cost'] = sprintf('%.2f', (float) $productEntity->price);
                    $confirmed[] = [
                        'field' => $costFieldKey,
                        'label' => "Line {$lineIndex} Rate",
                        'display' => sprintf('₹%.2f (Master Price)', (float) $productEntity->price),
                        'source' => 'erp_default',
                    ];
                } else {
                    // Default to 0.00 if rate is unknown or not provided yet (rate to be finalized on bill)
                    $cleanLine['net_unit_cost'] = '0.00';
                    $confirmed[] = [
                        'field' => $costFieldKey,
                        'label' => "Line {$lineIndex} Rate",
                        'display' => '₹0.00 (Pending rate)',
                        'source' => 'erp_default',
                    ];
                }
            } else {
                $priceFieldKey = "items[{$clientLineId}].net_unit_price";
                $userPrice = $line['net_unit_price'] ?? $line['net_unit_cost'] ?? $line['rate'] ?? $line['unit_price'] ?? null;
                if ($userPrice !== null && is_numeric($userPrice) && (float) $userPrice >= 0) {
                    $priceVal = (float) $userPrice;
                    $confirmed[] = [
                        'field' => $priceFieldKey,
                        'label' => "Line {$lineIndex} Unit Price",
                        'display' => sprintf('₹%.2f', $priceVal),
                        'source' => 'user',
                    ];
                    $cleanLine['net_unit_price'] = sprintf('%.2f', $priceVal);
                } elseif ($productEntity && isset($productEntity->price) && (float) $productEntity->price > 0) {
                    $cleanLine['net_unit_price'] = sprintf('%.2f', (float) $productEntity->price);
                    $confirmed[] = [
                        'field' => $priceFieldKey,
                        'label' => "Line {$lineIndex} Unit Price",
                        'display' => sprintf('₹%.2f (Master Price)', (float) $productEntity->price),
                        'source' => 'erp_default',
                    ];
                } else {
                    $cleanLine['net_unit_price'] = '0.00';
                    $confirmed[] = [
                        'field' => $priceFieldKey,
                        'label' => "Line {$lineIndex} Unit Price",
                        'display' => '₹0.00 (Pending rate)',
                        'source' => 'erp_default',
                    ];
                }
            }

            // 4. Evaluate Unit for this line
            $unitKey = "items[{$clientLineId}].".($isPurchase ? 'purchase_unit_id' : 'sale_unit_id');
            if (($batchResult->getResolution($unitKey)['status'] ?? null) === 'none') {
                $issues[] = new FieldIssue(field: $unitKey, label: "Line {$lineIndex} Unit of Measure", status: 'invalid',
                    reason: $batchResult->getResolution($unitKey)['error'], control: 'entity_select', clientLineId: $clientLineId, entityType: 'unit');
            }
            if ($isPurchase) {
                $unitFieldKey = "items[{$clientLineId}].purchase_unit_id";
                $resolvedUnitId = $batchResult->getResolvedId($unitFieldKey);
                if ($resolvedUnitId !== null) {
                    $cleanLine['purchase_unit_id'] = (int) $resolvedUnitId;
                } elseif ($productEntity) {
                    $cleanLine['purchase_unit_id'] = (int) ($productEntity->purchase_unit_id ?? $productEntity->unit_id ?? 1);
                } else {
                    $cleanLine['purchase_unit_id'] = (int) ($line['purchase_unit_id'] ?? 1);
                }
            } else {
                $unitFieldKey = "items[{$clientLineId}].sale_unit_id";
                $resolvedUnitId = $batchResult->getResolvedId($unitFieldKey);
                if ($resolvedUnitId !== null) {
                    $cleanLine['sale_unit_id'] = (int) $resolvedUnitId;
                } elseif ($productEntity) {
                    $cleanLine['sale_unit_id'] = (int) ($productEntity->sale_unit_id ?? $productEntity->unit_id ?? 1);
                } else {
                    $cleanLine['sale_unit_id'] = (int) ($line['sale_unit_id'] ?? 1);
                }
            }

            if (isset($line['discount']) && is_numeric($line['discount'])) {
                $cleanLine['discount'] = sprintf('%.2f', (float) $line['discount']);
            }
            if (isset($line['tax_rate']) && is_numeric($line['tax_rate'])) {
                $cleanLine['tax_rate'] = sprintf('%.2f', (float) $line['tax_rate']);
            }
            if (isset($line['note'])) {
                $cleanLine['note'] = (string) $line['note'];
            }

            $cleanLines[] = $cleanLine;
        }

        $cleanSlots[$req->fieldPath] = $cleanLines;
    }

    /**
     * Build the Three-Way Disambiguation Triad for an entity slot issue.
     *
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $prefilledData
     * @return list<ResolutionOption>
     */
    private function buildTriadOptions(
        string $entityType,
        string $field,
        string $label,
        ?string $query,
        WorkflowTaskContext $taskContext,
        array $candidates = [],
        array $prefilledData = [],
    ): array {
        $cleanQuery = trim((string) $query);

        [$permission, $capabilityKey, $searchEndpoint] = match ($entityType) {
            'supplier' => ['suppliers-add', 'setup.supplier.create.v1', '/api/v1/ai/entity-options?type=supplier'],
            'warehouse' => ['warehouse', 'setup.warehouse.create.v1', '/api/v1/ai/entity-options?type=warehouse'],
            'product' => ['products-add', 'setup.product.create.v1', '/api/v1/ai/entity-options?type=product'],
            'customer' => ['customers-add', 'setup.customer.create.v1', '/api/v1/ai/entity-options?type=customer'],
            'unit' => ['unit', 'setup.unit.create.v1', '/api/v1/ai/entity-options?type=unit'],
            default => [null, null, null],
        };

        $canCreate = false;
        $disabledReason = null;

        if ($permission !== null && $capabilityKey !== null) {
            $companyId = $taskContext->companyId();
            if ($companyId !== null) {
                $context = new CompanyContext(
                    $companyId,
                    $taskContext->branchId(),
                    $taskContext->financialYearId()
                );
                $canCreate = $this->permissions->allows($permission, $context, $taskContext->actorId());
                if (!$canCreate) {
                    $disabledReason = "Requires {$permission} permission";
                }
            } else {
                $disabledReason = 'Tenant company context required';
            }
        } else {
            $disabledReason = 'Creation capability not configured for this entity type';
        }

        $prefilled = array_merge([
            'name' => $cleanQuery,
        ], $prefilledData);

        if (isset($prefilled['cost_price']) && !isset($prefilled['cost'])) {
            $prefilled['cost'] = $prefilled['cost_price'];
        } elseif (isset($prefilled['cost']) && !isset($prefilled['cost_price'])) {
            $prefilled['cost_price'] = $prefilled['cost'];
        }

        return [
            new ResolutionOption(
                key: 'select_existing',
                label: "Select an existing {$label}",
                description: "Search active {$label} records in your ERP directory",
                searchEndpoint: $searchEndpoint,
            ),
            new ResolutionOption(
                key: 'create_new',
                label: "Create a new {$label}",
                description: "New {$label} setup will collect required details before approval",
                capabilityKey: $capabilityKey,
                canCreate: $canCreate,
                disabledReason: $disabledReason,
                prefilledSlots: array_filter($prefilled, static fn($v): bool => $v !== null && $v !== ''),
            ),
            new ResolutionOption(
                key: 'correct_name',
                label: "Correct the {$label} name",
                description: 'Fix typos or re-enter search query inline',
            ),
        ];
    }
}
