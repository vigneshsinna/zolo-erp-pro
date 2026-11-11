<?php

declare(strict_types=1);

namespace App\Ai\Services;

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
                $this->evaluateListRequirement($req, $taskContext, $slots, $batchResult, $issues, $confirmed, $cleanSlots);
                continue;
            }

            $this->evaluateScalarRequirement($req, $taskContext, $slots, $batchResult, $issues, $confirmed, $cleanSlots);
        }

        // Add downstream pending checks when prerequisite entities are unresolved
        $hasSupplierIssue = collect($issues)->contains(fn (FieldIssue $i) => $i->field === 'supplier_id');
        if ($hasSupplierIssue) {
            $pendingChecks[] = 'Authoritative GST determination and party tax calculation';
        }

        $hasCustomerIssue = collect($issues)->contains(fn (FieldIssue $i) => $i->field === 'customer_id');
        if ($hasCustomerIssue) {
            $pendingChecks[] = 'Customer credit limit check and GST place-of-supply determination';
        }

        $hasProductIssue = collect($issues)->contains(fn (FieldIssue $i) => str_contains($i->field, 'product_id') || str_contains($i->field, 'qty'));
        if ($hasProductIssue) {
            $pendingChecks[] = 'Commercial order line pricing and grand total reconciliation';
        }

        // If no blocking entity or format issues exist, run authoritative domain schema validation
        if (empty($issues)) {
            $validationErrors = $provider->validate($taskContext, $cleanSlots);
            foreach ($validationErrors as $field => $message) {
                $issues[] = new FieldIssue(
                    field: $field,
                    label: ucwords(str_replace(['.', '_'], ' ', $field)),
                    status: 'invalid',
                    reason: $message,
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
    ): void {
        $field = $req->fieldPath;
        $resolvedId = $batchResult->getResolvedId($field);
        $entity = $batchResult->getEntity($field);
        $resolution = $batchResult->getResolution($field);

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
                'display' => (string) $slots[$field],
                'source' => 'user',
            ];
            $cleanSlots[$field] = $slots[$field];
            return;
        }

        // Check default policy
        $default = $req->resolveDefault($taskContext, $slots);
        if ($default !== null) {
            $confirmed[] = [
                'field' => $field,
                'label' => $req->label,
                'display' => (string) $default,
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
    ): void {
        $rawItems = (array) ($slots[$req->fieldPath] ?? []);

        if (empty($rawItems)) {
            if ($req->isRequired($slots, $taskContext)) {
                $issues[] = new FieldIssue(
                    field: $req->fieldPath,
                    label: $req->label,
                    status: 'missing',
                    reason: 'At least one line item is required.',
                    control: 'list',
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
                    'display' => sprintf('%.2f units', $qtyVal),
                    'source' => 'user',
                ];
                $cleanLine['qty'] = sprintf('%.2f', $qtyVal);
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
            $isSale = isset($slots['customer_id']) || isset($slots['customer_query']) || isset($line['net_unit_price']) || isset($line['unit_price']);
            if ($isSale) {
                $priceFieldKey = "items[{$clientLineId}].net_unit_price";
                $userPrice = $line['net_unit_price'] ?? $line['unit_price'] ?? null;
                if ($userPrice !== null && is_numeric($userPrice) && (float) $userPrice >= 0) {
                    $priceVal = (float) $userPrice;
                    $confirmed[] = [
                        'field' => $priceFieldKey,
                        'label' => "Line {$lineIndex} Unit Price",
                        'display' => sprintf('₹%.2f', $priceVal),
                        'source' => 'user',
                    ];
                    $cleanLine['net_unit_price'] = sprintf('%.2f', $priceVal);
                } elseif ($productEntity && isset($productEntity->price)) {
                    $cleanLine['net_unit_price'] = sprintf('%.2f', (float) $productEntity->price);
                    $confirmed[] = [
                        'field' => $priceFieldKey,
                        'label' => "Line {$lineIndex} Unit Price",
                        'display' => sprintf('₹%.2f (Master Price)', (float) $productEntity->price),
                        'source' => 'erp_default',
                    ];
                }
            } else {
                $costFieldKey = "items[{$clientLineId}].net_unit_cost";
                if (isset($line['net_unit_cost']) && is_numeric($line['net_unit_cost']) && (float) $line['net_unit_cost'] >= 0) {
                    $costVal = (float) $line['net_unit_cost'];
                    $confirmed[] = [
                        'field' => $costFieldKey,
                        'label' => "Line {$lineIndex} Unit Cost",
                        'display' => sprintf('₹%.2f', $costVal),
                        'source' => 'user',
                    ];
                    $cleanLine['net_unit_cost'] = sprintf('%.2f', $costVal);
                } elseif ($productEntity && isset($productEntity->cost)) {
                    $cleanLine['net_unit_cost'] = sprintf('%.2f', (float) $productEntity->cost);
                    $confirmed[] = [
                        'field' => $costFieldKey,
                        'label' => "Line {$lineIndex} Unit Cost",
                        'display' => sprintf('₹%.2f (Master Cost)', (float) $productEntity->cost),
                        'source' => 'erp_default',
                    ];
                }
            }

            // 4. Evaluate Unit for this line
            if ($isSale) {
                $unitFieldKey = "items[{$clientLineId}].sale_unit_id";
                $resolvedUnitId = $batchResult->getResolvedId($unitFieldKey);
                if ($resolvedUnitId !== null) {
                    $cleanLine['sale_unit_id'] = (int) $resolvedUnitId;
                } elseif ($productEntity) {
                    $cleanLine['sale_unit_id'] = (int) ($productEntity->sale_unit_id ?? $productEntity->unit_id ?? 1);
                }
            } else {
                $unitFieldKey = "items[{$clientLineId}].purchase_unit_id";
                $resolvedUnitId = $batchResult->getResolvedId($unitFieldKey);
                if ($resolvedUnitId !== null) {
                    $cleanLine['purchase_unit_id'] = (int) $resolvedUnitId;
                } elseif ($productEntity) {
                    $cleanLine['purchase_unit_id'] = (int) ($productEntity->purchase_unit_id ?? $productEntity->unit_id ?? 1);
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
            'supplier' => ['suppliers-add', 'setup.supplier.create.v1', '/api/v1/parties?type=supplier'],
            'warehouse' => ['warehouse', 'setup.warehouse.create.v1', '/api/v1/warehouses'],
            'product' => ['products-add', 'setup.product.create.v1', '/api/v1/products'],
            'customer' => ['customers-add', 'setup.customer.create.v1', '/api/v1/parties?type=customer'],
            'unit' => ['unit', 'setup.unit.create.v1', '/api/v1/units'],
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
                prefilledSlots: array_filter($prefilled, static fn ($v): bool => $v !== null && $v !== ''),
            ),
            new ResolutionOption(
                key: 'correct_name',
                label: "Correct the {$label} name",
                description: 'Fix typos or re-enter search query inline',
            ),
        ];
    }
}
