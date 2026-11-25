<?php

declare(strict_types=1);

namespace App\Ai\Capabilities;

use App\Ai\Contracts\ActionInputSchemaInterface;
use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Services\Platform\CompanyContext;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

/**
 * Compiles closed JSON schemas and validates/normalizes draft inputs without
 * permitting authority injection, arbitrary extra fields, or float drift.
 */
final class CapabilitySchemaCompiler
{
    public const AUTHORITY_FIELDS = [
        'actor_id',
        'user_id',
        'company_id',
        'branch_id',
        'financial_year_id',
        'tenant_id',
        'tenant_boundary_id',
    ];

    public const DECIMAL_FIELDS = [
        'qty',
        'net_unit_cost',
        'net_unit_price',
        'price',
        'cost',
        'discount',
        'tax_rate',
        'order_discount',
        'shipping_cost',
        'amount',
        'unit_rate',
        'received_qty',
        'return_qty',
        'loss_qty',
        'loss_percent',
        'debit',
        'credit',
        'planned_qty',
        'fitting_charge',
        'tinting_cost',
    ];

    public const MAX_ITEMS = 50;
    public const MAX_STRING_LENGTH = 2000;
    public const MAX_INPUTS_JSON_BYTES = 30000;

    /**
     * Compile closed scalar JSON schema for read capabilities.
     *
     * @param array<string, mixed> $manifestSchema
     * @return array<string, mixed>
     */
    public function compileReadSchema(array $manifestSchema): array
    {
        return [
            'type' => 'object',
            'properties' => $manifestSchema['properties'] ?? [],
            'required' => array_values($manifestSchema['required'] ?? []),
            'additionalProperties' => false,
        ];
    }

    /**
     * Compile closed draft collection schema for prepare operations.
     * Required posting fields remain enforced server-side after entity resolution
     * so partial drafts trigger structured clarification rather than transport rejection.
     *
     * @return array<string, mixed>
     */
    public function compilePrepareDraftSchema(
        string $actionKey,
        ?ActionRequirementsProviderInterface $provider,
        string $channel = 'sdk',
    ): array {
        $properties = [];

        if ($actionKey === 'purchase_order.create.v1') {
            $properties = $this->purchaseOrderDraftProperties();
        } elseif ($provider !== null) {
            $properties = $this->compileFromRequirements($provider);
        }

        $properties['task_id'] = [
            'type' => 'string',
            'format' => 'uuid',
            'maxLength' => 36,
            'description' => 'Optional owned workflow task ID to resume or update.',
        ];
        $properties['expected_revision'] = [
            'type' => 'integer',
            'minimum' => 1,
            'description' => 'Expected task revision when resuming an existing draft task.',
        ];

        $draftObject = [
            'type' => 'object',
            'properties' => $properties,
            'required' => [],
            'additionalProperties' => false,
        ];

        if ($channel === 'mcp') {
            return [
                'type' => 'object',
                'properties' => [
                    'client_request_id' => [
                        'type' => 'string',
                        'minLength' => 8,
                        'maxLength' => 128,
                        'description' => 'Stable client-generated idempotency key for this preparation call.',
                    ],
                    'task_id' => [
                        'type' => 'string',
                        'format' => 'uuid',
                        'maxLength' => 36,
                        'description' => 'Optional owned workflow task ID when amending an existing draft.',
                    ],
                    'expected_revision' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Expected workflow task revision when continuing a draft.',
                    ],
                    'inputs' => $draftObject,
                ],
                'required' => ['client_request_id', 'inputs'],
                'additionalProperties' => false,
            ];
        }

        return $draftObject;
    }

    /**
     * Compile closed schema for scoped proposal/task status lookups.
     *
     * @return array<string, mixed>
     */
    public function compileStatusSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'proposal_id' => [
                    'type' => 'string',
                    'format' => 'uuid',
                    'maxLength' => 36,
                    'description' => 'Owned proposal UUID to inspect.',
                ],
                'task_id' => [
                    'type' => 'string',
                    'format' => 'uuid',
                    'maxLength' => 36,
                    'description' => 'Owned workflow task UUID to inspect.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function commandContractHash(string $actionKey, ActionDefinition $definition): string
    {
        $rules = [];
        if (is_a($definition->inputSchemaClass, ActionInputSchemaInterface::class, true)) {
            $rules = app($definition->inputSchemaClass)->rules();
        }

        return hash('sha256', json_encode([
            'action_key' => $actionKey,
            'risk_tier' => $definition->riskTier,
            'requires_approval' => $definition->requiresApproval,
            'rules' => $this->stringifyRules($rules),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    public function resultSchemaFor(string $operation): array
    {
        return match ($operation) {
            'read' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['ok', 'denied', 'error']],
                    'tool' => ['type' => 'string'],
                    'as_of' => ['type' => 'string'],
                    'data' => ['type' => 'object'],
                    'source_refs' => ['type' => 'array'],
                    'reconciled' => ['type' => 'boolean'],
                    'row_count' => ['type' => 'integer'],
                ],
            ],
            'prepare' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['needs_input', 'proposal_created', 'conflict', 'forbidden', 'unavailable', 'error']],
                    'task_id' => ['type' => 'string'],
                    'task_revision' => ['type' => 'integer'],
                    'proposal_id' => ['type' => 'string'],
                    'proposal_version' => ['type' => 'integer'],
                    'expires_at' => ['type' => 'string'],
                    'requires_approval' => ['type' => 'boolean'],
                    'review_url' => ['type' => 'string'],
                    'warnings' => ['type' => 'array'],
                    'errors' => ['type' => 'object'],
                ],
            ],
            'status' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['collecting', 'proposal_created', 'pending', 'approved', 'executing', 'completed', 'requires_reconciliation', 'rejected', 'cancelled', 'expired']],
                    'proposal_id' => ['type' => 'string'],
                    'proposal_version' => ['type' => 'integer'],
                    'task_id' => ['type' => 'string'],
                    'task_revision' => ['type' => 'integer'],
                    'requires_approval' => ['type' => 'boolean'],
                    'review_url' => ['type' => 'string'],
                    'receipt' => ['type' => 'object'],
                ],
            ],
            default => throw new InvalidArgumentException("Unsupported capability operation [{$operation}]."),
        };
    }

    /**
     * Validate and normalize draft prepare inputs against the descriptor's schema.
     * Rejects authority fields, unknown nested keys, NaN/INF, scientific notation,
     * and oversized arrays while preserving meaningful zeros and explicit null clears.
     *
     * @param array<string, mixed> $rawInput
     * @return array<string, mixed>
     */
    public function normalizeAndValidateDraftInput(
        CapabilityDescriptor $descriptor,
        array $rawInput,
        string $channel = 'sdk',
    ): array {
        $this->assertNoAuthorityFields($rawInput);

        $schema = $descriptor->draftInputSchema;
        if ($channel === 'mcp' && isset($schema['properties']['inputs']['properties'])) {
            $schema = $schema['properties']['inputs'];
        }

        $allowedProps = (array) ($schema['properties'] ?? []);
        $unknown = array_diff(array_keys($rawInput), array_keys($allowedProps));
        if ($unknown !== [] && $allowedProps !== []) {
            throw ValidationException::withMessages([
                'inputs' => 'Unsupported input field(s): ' . implode(', ', $unknown) . '.',
            ]);
        }

        return $this->normalizeObject($rawInput, $allowedProps);
    }

    /**
     * Decode legacy inputs_json string safely once with size, depth, and duplicate-key checks.
     *
     * @return array<string, mixed>
     */
    public function decodeLegacyInputsJson(string $json): array
    {
        if (strlen($json) > self::MAX_INPUTS_JSON_BYTES) {
            throw new InvalidArgumentException('Inputs JSON exceeds maximum size limit.');
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Malformed inputs JSON.', previous: $e);
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidArgumentException('Inputs JSON must decode to a JSON object.');
        }

        $this->assertNoAuthorityFields($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function assertNoAuthorityFields(array $data, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            $path = $prefix === '' ? $keyStr : "{$prefix}.{$keyStr}";
            if (in_array(strtolower($keyStr), self::AUTHORITY_FIELDS, true)) {
                throw ValidationException::withMessages([
                    $path => "Authority field [{$keyStr}] is never accepted from client or model inputs.",
                ]);
            }
            if (is_array($value)) {
                $this->assertNoAuthorityFields($value, $path);
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function normalizeObject(array $input, array $properties, string $prefix = ''): array
    {
        $normalized = [];

        foreach ($input as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $fieldSchema = $properties[$key] ?? null;

            if ($value === null) {
                $normalized[$key] = null;
                continue;
            }

            if ($key === 'items' && is_array($value)) {
                if (!array_is_list($value)) {
                    throw ValidationException::withMessages([$path => 'Line items must be a list.']);
                }
                if (count($value) > self::MAX_ITEMS) {
                    throw ValidationException::withMessages([$path => 'Line items exceed maximum limit of ' . self::MAX_ITEMS . '.']);
                }
                $itemProps = (array) ($fieldSchema['items']['properties'] ?? []);
                $items = [];
                foreach ($value as $idx => $row) {
                    if (!is_array($row) || ($row !== [] && array_is_list($row))) {
                        throw ValidationException::withMessages(["{$path}.{$idx}" => 'Each line item must be an object.']);
                    }
                    if ($itemProps !== []) {
                        $unknownNested = array_diff(array_keys($row), array_keys($itemProps));
                        if ($unknownNested !== []) {
                            throw ValidationException::withMessages([
                                "{$path}.{$idx}" => 'Unsupported nested item field(s): ' . implode(', ', $unknownNested) . '.',
                            ]);
                        }
                    }
                    $items[] = $this->normalizeObject($row, $itemProps, "{$path}.{$idx}");
                }
                $normalized[$key] = $items;
                continue;
            }

            if (is_array($value)) {
                if (count($value) > self::MAX_ITEMS) {
                    throw ValidationException::withMessages([$path => 'Array input exceeds maximum item count.']);
                }
                $nestedProps = (array) ($fieldSchema['properties'] ?? []);
                $normalized[$key] = $this->normalizeObject($value, $nestedProps, $path);
                continue;
            }

            if (is_float($value) && (is_nan($value) || is_infinite($value))) {
                throw ValidationException::withMessages([$path => 'Invalid floating-point number.']);
            }

            if (in_array((string) $key, self::DECIMAL_FIELDS, true)) {
                $normalized[$key] = $this->normalizeDecimal($value, $path);
                continue;
            }

            if (is_string($value)) {
                if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
                    throw ValidationException::withMessages([$path => 'Input text exceeds maximum length.']);
                }
                $normalized[$key] = $value;
                continue;
            }

            if (is_int($value) || is_bool($value)) {
                $normalized[$key] = $value;
                continue;
            }

            if (is_float($value)) {
                $normalized[$key] = $this->normalizeDecimal($value, $path);
                continue;
            }

            throw ValidationException::withMessages([$path => 'Unsupported input value type.']);
        }

        return $normalized;
    }

    private function normalizeDecimal(mixed $value, string $path): string
    {
        if (is_bool($value) || is_array($value) || is_object($value)) {
            throw ValidationException::withMessages([$path => 'Decimal field must be numeric.']);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw ValidationException::withMessages([$path => 'Invalid numeric value.']);
            }
            $formatted = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
            return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
        }

        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/[eE]/', $raw) || !preg_match('/^-?\d+(?:\.\d{1,8})?$/D', $raw)) {
            throw ValidationException::withMessages([$path => 'Decimal field must be a standard decimal number without scientific notation.']);
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseOrderDraftProperties(): array
    {
        return [
            'supplier_query' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Supplier name, company name, GSTIN, code, or ID.'],
            'supplier_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Explicit supplier ID if known.'],
            'items' => [
                'type' => 'array',
                'maxItems' => self::MAX_ITEMS,
                'description' => 'List of line items to include in the purchase order.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'client_line_id' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Stable client line identifier (e.g. line-0).'],
                        'product_query' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Product name, code, SKU, or alias.'],
                        'product_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Explicit product ID if known.'],
                        'qty' => ['type' => ['string', 'number'], 'description' => 'Quantity to order (exact decimal string or number).'],
                        'net_unit_cost' => ['type' => ['string', 'number'], 'description' => 'Optional agreed unit cost.'],
                        'discount' => ['type' => ['string', 'number'], 'description' => 'Optional line discount amount.'],
                        'tax_rate' => ['type' => ['string', 'number'], 'description' => 'Optional tax rate percentage override.'],
                        'uom' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Optional unit of measure (e.g. KG, PCS, MTR).'],
                        'purchase_unit_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional purchase unit ID.'],
                        'note' => ['type' => 'string', 'maxLength' => 500, 'description' => 'Optional line item note.'],
                        'sph' => ['type' => 'string', 'maxLength' => 16, 'description' => 'Optical sphere power (e.g. -2.00, +1.50).'],
                        'cyl' => ['type' => 'string', 'maxLength' => 16, 'description' => 'Optical cylinder power.'],
                        'axis' => ['type' => 'string', 'maxLength' => 8, 'description' => 'Optical axis 0-180.'],
                        'base' => ['type' => 'string', 'maxLength' => 20, 'description' => 'Optical base curve.'],
                        'addition' => ['type' => 'string', 'maxLength' => 16, 'description' => 'Optical addition power.'],
                        'lr' => ['type' => 'string', 'enum' => ['R', 'L', 'Pair'], 'description' => 'Eye designation.'],
                        'dia' => ['type' => 'string', 'maxLength' => 16, 'description' => 'Lens diameter in mm.'],
                        'optical_spec' => $this->opticalSpecObjectSchema(),
                    ],
                    'required' => [],
                    'additionalProperties' => false,
                ],
            ],
            'warehouse_query' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Destination warehouse name or ID.'],
            'warehouse_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Explicit destination warehouse ID if known.'],
            'date' => ['type' => 'string', 'format' => 'date', 'maxLength' => 10, 'description' => 'Optional order date in YYYY-MM-DD format.'],
            'payment_terms_type' => ['type' => 'string', 'enum' => ['cash', 'credit'], 'description' => 'Commercial terms only; cash does not post a payment.'],
            'credit_days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3650, 'description' => 'Credit days (0-3650). Cash terms require zero.'],
            'note' => ['type' => 'string', 'maxLength' => 1000, 'description' => 'Optional overall order note or reference.'],
            'order_discount' => ['type' => ['string', 'number'], 'description' => 'Optional overall order discount amount.'],
            'shipping_cost' => ['type' => ['string', 'number'], 'description' => 'Optional shipping or freight cost amount.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compileFromRequirements(ActionRequirementsProviderInterface $provider): array
    {
        $dummyContext = new WorkflowTaskContext(
            principal: new AiPrincipal(actorId: 1, companyId: 1, branchId: 1, financialYearId: 1),
            context: new CompanyContext(1, 1, 1),
            mode: 'company',
        );

        $props = [];
        foreach ($provider->requirements($dummyContext, []) as $req) {
            $this->appendRequirementProperty($props, $req);
        }

        return $props;
    }

    /**
     * @param array<string, mixed> $props
     */
    private function appendRequirementProperty(array &$props, ActionFieldRequirement $req): void
    {
        if (in_array($req->fieldPath, self::AUTHORITY_FIELDS, true)) {
            return;
        }

        if ($req->isList) {
            $itemProps = ['client_line_id' => ['type' => 'string', 'maxLength' => 64]];
            foreach ($req->itemRequirements as $child) {
                $this->appendRequirementProperty($itemProps, $child);
            }
            $props[$req->fieldPath] = [
                'type' => 'array',
                'maxItems' => self::MAX_ITEMS,
                'description' => $req->helpText ?? $req->label,
                'items' => [
                    'type' => 'object',
                    'properties' => $itemProps,
                    'required' => [],
                    'additionalProperties' => false,
                ],
            ];
            return;
        }

        if ($req->dataType === 'entity_reference') {
            $props[$req->fieldPath] = [
                'type' => 'integer',
                'minimum' => 1,
                'description' => $req->helpText ?? $req->label,
            ];
            $queryKey = $req->entityType ? "{$req->entityType}_query" : preg_replace('/_id$/', '_query', $req->fieldPath);
            if ($queryKey && $queryKey !== $req->fieldPath) {
                $props[$queryKey] = [
                    'type' => 'string',
                    'maxLength' => 200,
                    'description' => "Search query for {$req->label}.",
                ];
            }
            if ($req->entityType === 'unit') {
                $props['uom'] = ['type' => 'string', 'maxLength' => 64, 'description' => 'Unit of measure code or name.'];
            }
            return;
        }

        if ($req->fieldPath === 'optical_spec') {
            $props['optical_spec'] = $this->opticalSpecObjectSchema();
            return;
        }

        if ($req->fieldPath === 'prescription_json') {
            $props['prescription_json'] = [
                'type' => 'object',
                'properties' => [
                    'od_sph' => ['type' => 'string', 'maxLength' => 16],
                    'od_cyl' => ['type' => 'string', 'maxLength' => 16],
                    'od_axis' => ['type' => 'string', 'maxLength' => 8],
                    'od_add' => ['type' => 'string', 'maxLength' => 16],
                    'os_sph' => ['type' => 'string', 'maxLength' => 16],
                    'os_cyl' => ['type' => 'string', 'maxLength' => 16],
                    'os_axis' => ['type' => 'string', 'maxLength' => 8],
                    'os_add' => ['type' => 'string', 'maxLength' => 16],
                    'pd' => ['type' => 'string', 'maxLength' => 16],
                ],
                'required' => [],
                'additionalProperties' => false,
            ];
            return;
        }

        $props[$req->fieldPath] = match ($req->dataType) {
            'integer' => ['type' => 'integer', 'description' => $req->helpText ?? $req->label],
            'decimal_string' => ['type' => ['string', 'number'], 'description' => $req->helpText ?? $req->label],
            'date' => ['type' => 'string', 'format' => 'date', 'maxLength' => 10, 'description' => $req->helpText ?? $req->label],
            'boolean' => ['type' => 'boolean', 'description' => $req->helpText ?? $req->label],
            default => ['type' => 'string', 'maxLength' => self::MAX_STRING_LENGTH, 'description' => $req->helpText ?? $req->label],
        };
    }

    /** @return array<string, mixed> */
    private function opticalSpecObjectSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lens_family' => ['type' => 'string', 'maxLength' => 120],
                'lens_material' => ['type' => 'string', 'maxLength' => 80],
                'refractive_index' => ['type' => 'string', 'maxLength' => 20],
                'coating' => ['type' => 'string', 'maxLength' => 80],
                'lens_form' => ['type' => 'string', 'enum' => ['finished_stock', 'semi_finished_blank', 'custom_rx', 'service']],
                'sph' => ['type' => 'string', 'maxLength' => 16],
                'cyl' => ['type' => 'string', 'maxLength' => 16],
                'axis' => ['type' => 'string', 'maxLength' => 8],
                'addition' => ['type' => 'string', 'maxLength' => 16],
                'base_curve' => ['type' => 'string', 'maxLength' => 16],
                'dia' => ['type' => 'string', 'maxLength' => 16],
                'lr' => ['type' => 'string', 'enum' => ['R', 'L', 'Pair']],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, string>
     */
    private function stringifyRules(array $rules): array
    {
        $out = [];
        ksort($rules);
        foreach ($rules as $field => $rule) {
            if (is_string($rule)) {
                $out[(string) $field] = $rule;
            } elseif (is_array($rule)) {
                $parts = array_map(static fn ($r): string => is_object($r) ? $r::class : (string) $r, $rule);
                $out[(string) $field] = implode('|', $parts);
            }
        }
        return $out;
    }
}
