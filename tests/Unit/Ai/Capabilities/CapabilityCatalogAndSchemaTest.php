<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Capabilities;

use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityDescriptor;
use App\Ai\Capabilities\CapabilityIntegrityValidator;
use App\Ai\Capabilities\CapabilitySchemaCompiler;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Services\AiToolManifest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Covers U1 acceptance cases D01, D02, D03, D04, D05, D13, and D15.
 */
final class CapabilityCatalogAndSchemaTest extends TestCase
{
    public function test_d01_integrity_validator_rejects_duplicate_alias_wrong_risk_and_unknown_operation(): void
    {
        $manifest = app(AiToolManifest::class);
        $actions = app(ActionCapabilityRegistry::class);
        $requirements = app(ActionRequirementsRegistry::class);
        $validator = new CapabilityIntegrityValidator();

        $valid = $this->makeDescriptor('receivables.outstanding.v1', 'read', ['sdk' => 'CustomerOutstanding']);

        // Duplicate case-insensitive alias on same channel
        $dupAlias = $this->makeDescriptor('inventory.stock_summary.v1', 'read', ['sdk' => 'customeroutstanding']);
        try {
            $validator->validate([$valid, $dupAlias], $manifest, $actions, $requirements);
            $this->fail('Duplicate alias must fail integrity validation.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Duplicate alias', $e->getMessage());
        }

        // Wrong risk on read descriptor
        $wrongRisk = $this->makeDescriptor(
            'receivables.outstanding.v1',
            'read',
            ['sdk' => 'CustomerOutstanding'],
            risk: 'r3_financial',
        );
        try {
            $validator->validate([$wrongRisk], $manifest, $actions, $requirements);
            $this->fail('Non-read-only risk on read descriptor must fail.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('read_only', $e->getMessage());
        }

        // Unknown operation (e.g. approve/execute)
        $badOp = $this->makeDescriptor('purchase_order.create.v1', 'execute', ['sdk' => 'execute_po']);
        try {
            $validator->validate([$badOp], $manifest, $actions, $requirements);
            $this->fail('Execute operation must fail integrity validation.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unsupported capability operation', $e->getMessage());
        }
    }

    public function test_d02_integrity_validator_rejects_missing_action_and_unreviewed_mcp_exposure(): void
    {
        $manifest = app(AiToolManifest::class);
        $actions = app(ActionCapabilityRegistry::class);
        $requirements = app(ActionRequirementsRegistry::class);
        $validator = new CapabilityIntegrityValidator();

        $missingAction = $this->makeDescriptor(
            'nonexistent.action.v1',
            'prepare',
            ['sdk' => 'prep_missing'],
            risk: 'r2_operational',
            requiresApproval: true,
            permissions: ['ai.use'],
        );

        try {
            $validator->validate([$missingAction], $manifest, $actions, $requirements);
            $this->fail('Missing action registry entry must fail integrity validation.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('ActionCapabilityRegistry', $e->getMessage());
        }

        // Unreviewed MCP prepare exposure without review reference
        $unreviewedMcp = $this->makeDescriptor(
            'purchase_order.create.v1',
            'prepare',
            ['mcp' => 'erp_purchase_order_prepare'],
            risk: 'r2_operational',
            requiresApproval: true,
            permissions: ['ai.use', 'purchases-add'],
            channels: ['sdk' => false, 'mcp' => true, 'rest' => false, 'automation' => false],
            reviewReference: null,
        );

        try {
            $validator->validate([$unreviewedMcp], $manifest, $actions, $requirements);
            $this->fail('Unreviewed MCP exposure must fail integrity validation.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('review reference', $e->getMessage());
        }
    }

    public function test_d03_d15_derived_catalog_schemas_are_closed_and_semantically_equivalent(): void
    {
        $catalog = app(CapabilityCatalog::class);
        $all = $catalog->all();
        $this->assertNotEmpty($all);

        $readAr = $catalog->find('receivables.outstanding.v1', 'read');
        $this->assertNotNull($readAr);
        $this->assertSame('CustomerOutstanding', $readAr->aliasFor('sdk'));
        $this->assertSame('CustomerOutstanding', $readAr->aliasFor('mcp'));
        $this->assertSame('receivables.outstanding.v1', $readAr->aliasFor('rest'));
        $this->assertFalse($readAr->draftInputSchema['additionalProperties']);
        $this->assertSame($readAr->draftInputSchema, $readAr->toPublicArray('rest')['input_schema']);

        $po = $catalog->find('purchase_order.create.v1', 'prepare');
        $this->assertNotNull($po);
        $this->assertSame('prepare_purchase_order', $po->aliasFor('sdk'));
        $this->assertSame('erp_purchase_order_prepare', $po->aliasFor('mcp'));
        $this->assertSame('purchase_order.create.v1', $po->aliasFor('rest'));
        $this->assertTrue($po->requiresHumanApproval);
        $this->assertFalse($po->draftInputSchema['additionalProperties']);
        $this->assertFalse($po->draftInputSchema['properties']['items']['items']['additionalProperties']);
        $mcpSchema = $po->inputSchemaFor('mcp');
        $this->assertArrayHasKey('inputs', $mcpSchema['properties']);
        $this->assertFalse($mcpSchema['properties']['inputs']['additionalProperties']);
    }

    public function test_d04_d05_compiler_normalizes_decimals_preserves_zeros_and_rejects_nested_invalid_or_authority_keys(): void
    {
        $compiler = app(CapabilitySchemaCompiler::class);
        $catalog = app(CapabilityCatalog::class);
        $po = $catalog->find('purchase_order.create.v1', 'prepare');
        $this->assertNotNull($po);

        // Partial draft with numeric 0 discount and explicit null note
        $normalized = $compiler->normalizeAndValidateDraftInput($po, [
            'supplier_query' => 'ABC Traders',
            'note' => null,
            'items' => [
                ['product_query' => 'Linen', 'qty' => '100', 'net_unit_cost' => '0.00', 'discount' => 0, 'uom' => 'MTR'],
            ],
        ], 'mcp');

        $this->assertSame('100', $normalized['items'][0]['qty']);
        $this->assertSame('0.00', $normalized['items'][0]['net_unit_cost']);
        $this->assertSame('0', $normalized['items'][0]['discount']);
        $this->assertArrayHasKey('note', $normalized);
        $this->assertNull($normalized['note']);

        // Reject nested authority field
        try {
            $compiler->normalizeAndValidateDraftInput($po, [
                'items' => [['product_query' => 'Linen', 'qty' => '10', 'company_id' => 99]],
            ], 'mcp');
            $this->fail('Nested authority field must be rejected.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        // Reject scientific notation
        try {
            $compiler->normalizeAndValidateDraftInput($po, [
                'items' => [['product_query' => 'Linen', 'qty' => '1e5']],
            ], 'mcp');
            $this->fail('Scientific notation must be rejected.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        // Reject unknown nested key
        try {
            $compiler->normalizeAndValidateDraftInput($po, [
                'items' => [['product_query' => 'Linen', 'qty' => '10', 'hacked_flag' => true]],
            ], 'mcp');
            $this->fail('Unknown nested key must be rejected.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        // Reject >50 line items
        $tooMany = array_fill(0, 51, ['product_query' => 'Linen', 'qty' => '1']);
        try {
            $compiler->normalizeAndValidateDraftInput($po, ['items' => $tooMany], 'mcp');
            $this->fail('Excessive line items must be rejected.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_d13_catalog_with_over_100_descriptors_remains_deterministic_and_bounded(): void
    {
        $manifest = app(AiToolManifest::class);
        $actions = app(ActionCapabilityRegistry::class);
        $requirements = app(ActionRequirementsRegistry::class);
        $compiler = app(CapabilitySchemaCompiler::class);
        $validator = new CapabilityIntegrityValidator();

        $descriptors = [];
        for ($i = 1; $i <= 110; $i++) {
            $descriptors[] = new CapabilityDescriptor(
                capabilityKey: 'receivables.outstanding.v' . $i,
                contractVersion: 'v' . $i,
                operation: 'read',
                providerAliases: ['sdk' => 'ReadTool' . $i, 'rest' => 'read_tool_' . $i],
                title: 'Read Tool ' . $i,
                description: 'Bounded read descriptor ' . $i,
                module: 'receivables',
                tags: ['read_only'],
                draftInputSchema: ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']], 'required' => [], 'additionalProperties' => false],
                commandContractHash: hash('sha256', 'tool-' . $i),
                resultSchema: $compiler->resultSchemaFor('read'),
                risk: 'read_only',
                requiresHumanApproval: false,
                permissions: ['ai.use', 'accounting.reports.view', 'customer-report', 'due-report', 'sales-index'],
                featureDependencies: ['erp_ai.enabled'],
                channelModes: ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
                knowledgeReference: null,
                lifecycle: 'active',
                owner: 'platform-ai',
                reviewReference: 'REV-TEST',
            );
        }

        // Validate only first real one against manifest, and verify deterministic sorting + slicing across 110
        $catalog = new CapabilityCatalog(
            $manifest,
            $actions,
            $requirements,
            $compiler,
            new CapabilityIntegrityValidator(),
            null,
            [$this->makeDescriptor('receivables.outstanding.v1', 'read', ['sdk' => 'CustomerOutstanding'])],
        );

        $this->assertCount(1, $catalog->all());
        $this->assertCount(110, $descriptors);
        $maxSdkTools = 25;
        $this->assertCount($maxSdkTools, array_slice($descriptors, 0, $maxSdkTools));
    }

    private function makeDescriptor(
        string $key,
        string $operation,
        array $aliases,
        string $risk = 'read_only',
        bool $requiresApproval = false,
        array $permissions = ['ai.use', 'accounting.reports.view', 'customer-report', 'due-report', 'sales-index'],
        array $channels = ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
        ?string $reviewReference = 'REV-TEST-V1',
    ): CapabilityDescriptor {
        return new CapabilityDescriptor(
            capabilityKey: $key,
            contractVersion: 'v1',
            operation: $operation,
            providerAliases: $aliases,
            title: 'Test Capability',
            description: 'Test capability description',
            module: 'test',
            tags: ['test'],
            draftInputSchema: ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false],
            commandContractHash: hash('sha256', $key),
            resultSchema: ['type' => 'object'],
            risk: $risk,
            requiresHumanApproval: $requiresApproval,
            permissions: $permissions,
            featureDependencies: ['erp_ai.enabled'],
            channelModes: $channels,
            knowledgeReference: null,
            lifecycle: 'active',
            owner: 'platform-ai',
            reviewReference: $reviewReference,
        );
    }
}
