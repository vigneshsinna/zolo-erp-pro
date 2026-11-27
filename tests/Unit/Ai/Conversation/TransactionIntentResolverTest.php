<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Conversation;

use App\Ai\Conversation\TransactionIntentResolver;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Multimodal\Services\NormalizedInputBuilder;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Models\AiMediaAsset;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TransactionIntentResolverTest extends TestCase
{
    public function test_parameterized_explicit_aliases_negation_and_false_positives_zero_provider_calls(): void
    {
        Http::preventStrayRequests();
        $resolver = new TransactionIntentResolver();

        $cases = [
            ['create sales bill from the image', 'resolved', 'sales_invoice.create.v1'],
            ['prepare sales invoice for dealer', 'resolved', 'sales_invoice.create.v1'],
            ['raise sale bill', 'resolved', 'sales_invoice.create.v1'],
            ['create purchase bill from this image', 'resolved', 'purchase_bill.create.v1'],
            ['book vendor bill INV-10', 'resolved', 'purchase_bill.create.v1'],
            ['enter supplier invoice 100', 'resolved', 'purchase_bill.create.v1'],
            ['prepare a purchase order for ABC', 'resolved', 'purchase_order.create.v1'],
            ['create sales order for Zenith', 'resolved', 'sales_order.create.v1'],
            ['not purchase, create sales bill', 'resolved', 'sales_invoice.create.v1'],
            ['create a bill', 'ambiguous_neutral', null],
            ['prepare tax invoice from image', 'ambiguous_neutral', null],
            ['create sales and purchase bills', 'multiple_conflicting', null],
            ['create purchase order for product "Billabong Frame"', 'resolved', 'purchase_order.create.v1'],
            ['how many sales bills were created today?', 'readonly', null],
            ['show customer outstanding', 'readonly', null],
        ];

        foreach ($cases as [$input, $expectedStatus, $expectedAction]) {
            $analysis = $resolver->analyzeText($input);
            $this->assertSame($expectedStatus, $analysis['status'], "Failed status for input: {$input}");
            $this->assertSame($expectedAction, $analysis['action'], "Failed action for input: {$input}");
        }

        Http::assertNothingSent();
    }

    public function test_media_decision_blocks_sales_on_incoming_supplier_invoice_without_mutating_to_purchase(): void
    {
        $registry = new ActionCapabilityRegistry();
        $registry->registerCommercialCapabilities();
        $builder = new NormalizedInputBuilder($registry);

        $principal = new AiPrincipal(
            actorId: 1,
            companyId: 1,
            branchId: 1,
            financialYearId: 1,
            permittedToolNames: ['purchase_bill.create.v1', 'sales_invoice.create.v1']
        );

        $asset = new AiMediaAsset();
        $asset->id = '00000000-0000-4000-8000-000000000011';
        $asset->source_kind = 'document';
        $asset->company_id = 1;

        $extractedIncoming = [
            'document_type' => 'purchase_bill',
            'document_kind' => 'invoice',
            'supplier_name' => 'External Lens Vendor',
            'buyer_name' => 'My Optical Co',
            'invoice_number' => 'SUP-44',
            'erp_checks' => [
                'company_role' => 'own_buyer',
                'document_direction' => 'incoming',
                'company_mismatch' => false,
            ],
            'lines' => [
                ['description' => 'Single Vision 1.56', 'quantity' => 2, 'unit_price' => 400],
            ],
        ];

        $norm = $builder->build(
            principal: $principal,
            asset: $asset,
            extracted: $extractedIncoming,
            extractionId: 'ext-11',
            extractionRevision: 1,
            modelKey: 'test-model',
            companionText: 'Create sales bill from the image',
        );

        $this->assertSame('sales_invoice.create.v1', $norm->intentKey);
        $this->assertFalse($norm->isActionable);
        $this->assertSame('source_role_conflict', $norm->intentDecision['decision_status']);
        $this->assertSame('SALES_REQUEST_ON_INCOMING_SUPPLIER_INVOICE', $norm->intentDecision['reason_code']);
        $this->assertContains('use_as_resale_reference', $norm->intentDecision['allowed_next_actions']);
        $this->assertContains('record_purchase_instead', $norm->intentDecision['allowed_next_actions']);
    }

    public function test_forged_action_override_is_blocked(): void
    {
        $registry = new ActionCapabilityRegistry();
        $registry->registerCommercialCapabilities();
        $builder = new NormalizedInputBuilder($registry);

        $principal = new AiPrincipal(
            actorId: 1,
            companyId: 1,
            branchId: 1,
            financialYearId: 1,
            permittedToolNames: ['purchase_order.create.v1']
        );

        $asset = new AiMediaAsset();
        $asset->id = '00000000-0000-4000-8000-000000000012';
        $asset->source_kind = 'document';
        $asset->company_id = 1;

        $norm = $builder->build(
            principal: $principal,
            asset: $asset,
            extracted: ['document_type' => 'purchase_bill', 'lines' => []],
            extractionId: 'ext-12',
            extractionRevision: 1,
            modelKey: 'test-model',
            overrideIntentKey: 'admin.arbitrary.execute.v1',
        );

        $this->assertNull($norm->intentKey);
        $this->assertFalse($norm->isActionable);
        $this->assertSame('blocked', $norm->intentDecision['decision_status']);
        $this->assertSame('INVALID_ACTION_OVERRIDE', $norm->intentDecision['reason_code']);
    }

    public function test_audio_vs_typed_conflict_returns_needs_intent_confirmation(): void
    {
        $registry = new ActionCapabilityRegistry();
        $registry->registerCommercialCapabilities();
        $builder = new NormalizedInputBuilder($registry);

        $principal = new AiPrincipal(
            actorId: 1,
            companyId: 1,
            branchId: 1,
            financialYearId: 1,
            permittedToolNames: ['purchase_bill.create.v1', 'sales_invoice.create.v1']
        );

        $asset = new AiMediaAsset();
        $asset->id = '00000000-0000-4000-8000-000000000013';
        $asset->source_kind = 'audio';
        $asset->company_id = 1;

        $norm = $builder->build(
            principal: $principal,
            asset: $asset,
            extracted: ['transcript_text' => 'Create a purchase bill for ABC', 'items' => []],
            extractionId: 'ext-13',
            extractionRevision: 1,
            modelKey: 'test-model',
            companionText: 'Create a sales bill',
        );

        $this->assertNull($norm->intentKey);
        $this->assertFalse($norm->isActionable);
        $this->assertSame('needs_intent_confirmation', $norm->intentDecision['decision_status']);
        $this->assertSame('AUDIO_TEXT_INTENT_CONFLICT', $norm->intentDecision['reason_code']);
    }

    public function test_active_task_conflict_preserves_existing_draft(): void
    {
        $registry = new ActionCapabilityRegistry();
        $registry->registerCommercialCapabilities();
        $builder = new NormalizedInputBuilder($registry);

        $principal = new AiPrincipal(
            actorId: 1,
            companyId: 1,
            branchId: 1,
            financialYearId: 1,
            permittedToolNames: ['purchase_bill.create.v1', 'sales_invoice.create.v1']
        );

        $asset = new AiMediaAsset();
        $asset->id = '00000000-0000-4000-8000-000000000014';
        $asset->source_kind = 'document';
        $asset->company_id = 1;

        $norm = $builder->build(
            principal: $principal,
            asset: $asset,
            extracted: ['document_type' => 'sales_invoice', 'lines' => []],
            extractionId: 'ext-14',
            extractionRevision: 1,
            modelKey: 'test-model',
            companionText: 'Create sales bill from this image',
            activeTaskAction: 'purchase_bill.create.v1',
        );

        $this->assertSame('sales_invoice.create.v1', $norm->intentKey);
        $this->assertFalse($norm->isActionable);
        $this->assertSame('active_task_conflict', $norm->intentDecision['decision_status']);
        $this->assertSame('purchase_bill.create.v1', $norm->intentDecision['active_task_action']);
    }
}
