<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Services\AiToolManifest;
use Tests\TestCase;

final class DriftIntegrationTest extends TestCase
{
    public function test_real_container_registers_every_reported_action_and_its_input_provider(): void
    {
        $registry = app(ActionCapabilityRegistry::class);
        $requirements = app(ActionRequirementsRegistry::class);
        foreach (['quotation.create.v1', 'grn.create.v1', 'purchase_bill.create.v1', 'delivery_challan.create.v1',
            'sales_invoice.create.v1', 'payment_receipt.create.v1', 'payment_reminder.prepare.v1', 'payment_reminder.send.v1',
            'stock_transfer.create.v1', 'stock_adjust.create.v1', 'stock_adjustment.create.v1', 'journal_entry.create.v1',
            'production_order.create.v1', 'production_order.complete.v1', 'job_work.outward.v1', 'job_work.inward.v1',
            'setup.account.create.v1', 'setup.pricelist.update.v1', 'setup.products.import.v1',
            'setup.opening_stock.create.v1', 'setup.opening_balance.create.v1'] as $key) {
            $this->assertTrue($registry->has($key), "Action missing from the application container: {$key}");
            $this->assertTrue($requirements->has($key), "Clarification provider missing: {$key}");
            $definition = $registry->get($key);
            $this->assertTrue($definition->requiresApproval);
            $this->assertFalse($definition->enabledByDefault);
        }
    }

    public function test_document_retrieval_discovery_follows_configuration_without_duplicate_tools(): void
    {
        config(['ai_documents.enabled' => false, 'ai_documents.qdrant.enabled' => false]);
        $this->app->forgetInstance(AiToolManifest::class);
        $this->assertFalse(app(AiToolManifest::class)->has('knowledge.documents.v1'));
        config(['ai_documents.enabled' => true, 'ai_documents.qdrant.enabled' => true]);
        $this->app->forgetInstance(AiToolManifest::class);
        $manifest = app(AiToolManifest::class);
        $this->assertTrue($manifest->has('knowledge.documents.v1'));
        $principal = new AiPrincipal(1, 1, 1, 1, permittedToolNames: ['knowledge.documents.v1']);
        $tools = $manifest->sdkTools($principal);
        $this->assertCount(1, $tools);
        $this->assertSame('read_document_knowledge', $tools[0]->name());
    }

    public function test_reported_surfaces_are_mounted_in_the_real_router(): void
    {
        $routes = app('router')->getRoutes();
        foreach (['ai.chat.stream', 'api.v1.ai.chat.stream', 'ai.mcp', 'ai.actions.execute-async',
            'api.v1.ai.actions.execution', 'ai.schedules.index', 'api.v1.ai.schedules.store'] as $name) {
            $this->assertNotNull($routes->getByName($name), "Missing application route: {$name}");
        }
    }
}
