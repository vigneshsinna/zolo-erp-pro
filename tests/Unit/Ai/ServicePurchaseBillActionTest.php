<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Knowledge\BusinessKnowledgeRegistry;
use Tests\TestCase;

final class ServicePurchaseBillActionTest extends TestCase
{
    public function test_service_bill_is_not_registered_by_default(): void
    {
        config(['ai_business_knowledge.service_bill_enabled' => false]);
        $registry = new ActionCapabilityRegistry;
        $registry->registerCommercialCapabilities();
        $this->assertFalse($registry->has('purchase_bill.service.create.v1'));
        $this->assertFalse((new ActionRequirementsRegistry)->has('purchase_bill.service.create.v1'));
    }

    public function test_service_bill_requires_explicit_capability_registration(): void
    {
        config(['ai_business_knowledge.service_bill_enabled' => true]);
        $registry = new ActionCapabilityRegistry;
        $registry->registerCommercialCapabilities();
        $this->assertTrue($registry->has('purchase_bill.service.create.v1'));
        $definition = $registry->get('purchase_bill.service.create.v1');
        $this->assertTrue($definition->requiresApproval);
        $this->assertFalse($definition->enabledByDefault);
        $this->assertSame('r3_financial', $definition->riskTier);
        $this->assertTrue((new ActionRequirementsRegistry)->has('purchase_bill.service.create.v1'));
    }

    public function test_bill_knowledge_requires_business_signoff_before_planning(): void
    {
        $registry = new BusinessKnowledgeRegistry;
        $this->assertNotNull($registry->find('process.service_payable.v1'));
        $this->assertNull($registry->find('process.service_payable.v1', reviewedOnly: true));
    }
}
