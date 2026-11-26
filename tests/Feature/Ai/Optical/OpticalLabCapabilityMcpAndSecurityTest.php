<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Optical;

use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityDispatcher;
use App\Ai\Capabilities\CapabilityExposurePolicy;
use App\Ai\Capabilities\CapabilityIntegrityValidator;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Infrastructure\McpClientGrant;
use App\Ai\Knowledge\BusinessKnowledgeRegistry;
use App\Ai\Knowledge\BusinessWorkflowMetrics;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiToolManifest;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Optical\OpticalRxJobService;
use App\Services\Platform\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class OpticalLabCapabilityMcpAndSecurityTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private Company $otherCompany;
    private CompanyBranch $branch;
    private Customer $customer;
    private Warehouse $warehouse;
    private Product $product;
    private User $actor;
    private CompanyContext $context;
    private AiPrincipal $principal;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'erp_ai.enabled' => true,
            'erp_ai.read_only' => false,
            'ai_actions.foundation_enabled' => true,
            'ai_actions.execution_enabled' => true,
            'ai_business_knowledge.opticals_enabled' => true,
            'ai_business_knowledge.opticals_lab_enabled' => true,
            'ai_business_knowledge.opticals_mcp_enabled' => false,
            'ai_infrastructure.mcp.enabled' => true,
        ]);
        $this->app->forgetInstance(AiFeatureGate::class);
        $this->app->forgetInstance(CapabilityCatalog::class);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->company = Company::create([
            'legal_name' => 'Apex Rx Optical Lab Ltd',
            'trade_name' => 'Apex Rx Optical Lab',
            'code' => 'RXLAB_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);
        $this->otherCompany = Company::create([
            'legal_name' => 'Stock Only Optical Co Ltd',
            'trade_name' => 'Stock Only Optical Co',
            'code' => 'STKOPT_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        DB::table('company_industry_settings')->insert([
            [
                'company_id' => $this->company->id,
                'profile_key' => 'opticals',
                'subtype' => 'lens_manufacturing',
                'settings_json' => json_encode(['rx_lab' => true]),
                'updated_by' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $this->otherCompany->id,
                'profile_key' => 'opticals',
                'subtype' => 'wholesale_distributor',
                'settings_json' => json_encode(['rx_lab' => false]),
                'updated_by' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->branch = $this->company->branches()->create(['code' => 'LAB1', 'name' => 'Chennai Rx Lab', 'branch_type' => 'branch']);
        $fy = $this->company->fiscalYears()->create([
            'name' => 'FY 2026-27',
            'code' => 'FYRX_' . uniqid(),
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_default' => true,
        ]);

        $this->warehouse = Warehouse::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'name' => 'Rx Lab Vault',
            'address' => 'Chennai Lab Park',
            'is_active' => true,
        ]);
        $this->customer = Customer::forceCreate([
            'company_id' => $this->company->id,
            'customer_group_id' => 1,
            'name' => 'Metro Eye Care',
            'phone_number' => '9822222222',
            'is_active' => true,
        ]);

        $unitId = Unit::forceCreate([
            'company_id' => $this->company->id,
            'unit_name' => 'Piece',
            'unit_code' => 'RXP_' . uniqid(),
            'is_active' => true,
        ])->id;

        $categoryId = \App\Models\Category::firstOrCreate(
            ['company_id' => $this->company->id, 'name' => 'Rx Lenses'],
            ['is_active' => true]
        )->id;

        $this->product = Product::forceCreate([
            'company_id' => $this->company->id,
            'code' => 'RX-PROG-' . uniqid(),
            'name' => 'Custom Freeform Progressive 1.67',
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sale_unit_id' => $unitId,
            'purchase_unit_id' => $unitId,
            'cost' => 800,
            'price' => 1600,
            'qty' => 0,
            'is_active' => true,
        ]);

        $this->actor = User::forceCreate([
            'name' => 'Rx Lab Supervisor',
            'email' => 'rx_sup_' . uniqid() . '@example.test',
            'phone' => '9822222221',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->company->users()->attach($this->actor->id);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->actor->id,
            'branch_id' => $this->branch->id,
        ]);
        $this->actingAs($this->actor);

        $this->context = new CompanyContext($this->company->id, $this->branch->id, $fy->id);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->principal = new AiPrincipal(
            actorId: $this->actor->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $fy->id,
            permittedToolNames: [
                'optical.lens_candidates.v1',
                'optical.power_matrix.v1',
                'setup.product.create.v1',
                'setup.unit.create.v1',
                'setup.warehouse.create.v1',
                'setup.branch.create.v1',
            ],
        );
    }

    public function test_rx_lab_lifecycle_idempotency_revision_lock_qc_remake_and_pii_redaction(): void
    {
        $service = app(OpticalRxJobService::class);

        $idem = 'rx-job-' . uniqid();
        $job = $service->createJob($this->context, $this->actor->id, [
            'customer_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'fulfillment_route' => 'internal_lab',
            'qty_requested' => 2,
            'promised_date' => now()->addDays(3)->toDateString(),
            'wearer_name' => 'Secret Wearer',
            'optical_spec' => ['sph' => '-2.00', 'cyl' => '-0.75', 'axis' => 95, 'addition' => '+1.50', 'lr' => 'BOTH'],
        ], $idem);

        $replay = $service->createJob($this->context, $this->actor->id, [
            'customer_id' => $this->customer->id,
            'qty_requested' => 2,
            'optical_spec' => ['sph' => '-2.00'],
        ], $idem);
        $this->assertSame($job->id, $replay->id);

        $summary = $service->toSafeSummary($job);
        $this->assertArrayNotHasKey('wearer_name', $summary);

        $v1 = $service->transition($this->context, $this->actor->id, $job->id, 1, 'ready_for_work');
        try {
            $service->transition($this->context, $this->actor->id, $job->id, 1, 'in_process');
            $this->fail('Stale revision should throw ConflictHttpException.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }

        $v3 = $service->transition($this->context, $this->actor->id, $job->id, $v1->revision, 'in_process');
        $v4 = $service->transition($this->context, $this->actor->id, $job->id, $v3->revision, 'awaiting_qc');

        $qcFailed = $service->recordQcResult($this->context, $this->actor->id, $job->id, $v4->revision, 0, 2, 'Coating peel on LE');
        $this->assertSame('rework_required', $qcFailed->status);

        $remake = $service->createRemake($this->context, $this->actor->id, $job->id, 'Coating peel on LE', 'remake-' . uniqid());
        $this->assertSame($job->id, $remake->parent_job_id);
        $this->assertSame(2.0, (float) $remake->qty_requested);

        $otherBranch = $this->otherCompany->branches()->create(['code' => 'M1', 'name' => 'Main', 'branch_type' => 'branch']);
        $otherFy = $this->otherCompany->fiscalYears()->create([
            'name' => 'FY2627',
            'code' => 'FYSTK_' . uniqid(),
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_default' => true,
        ]);
        $stockOnlyCtx = new CompanyContext($this->otherCompany->id, $otherBranch->id, $otherFy->id);

        $this->expectException(AuthorizationException::class);
        $service->createJob($stockOnlyCtx, $this->actor->id, [
            'customer_id' => $this->customer->id,
            'qty_requested' => 1,
            'optical_spec' => ['sph' => '-1.00'],
        ], 'blocked-rx-' . uniqid());
    }

    public function test_shared_capability_catalog_setup_providers_mcp_gating_and_metrics(): void
    {
        $registry = app(ActionRequirementsRegistry::class);
        foreach (['setup.product.create.v1', 'setup.unit.create.v1', 'setup.warehouse.create.v1', 'setup.branch.create.v1'] as $setupKey) {
            $this->assertTrue($registry->has($setupKey), "Missing setup requirements provider for {$setupKey}");
        }

        $catalog = app(CapabilityCatalog::class);
        $descriptors = array_values($catalog->all());
        app(CapabilityIntegrityValidator::class)->validate(
            $descriptors,
            app(AiToolManifest::class),
            app(ActionCapabilityRegistry::class),
            $registry,
            app(BusinessKnowledgeRegistry::class),
        );

        $policy = app(CapabilityExposurePolicy::class);
        $matrixDesc = $catalog->find('optical.power_matrix.v1', 'read');
        $this->assertNotNull($matrixDesc);

        $this->assertTrue($policy->authorize($this->principal, 'sdk', $matrixDesc));

        $grant = new McpClientGrant(
            bindingId: 'mcp-binding-1',
            clientIdentifier: 'test-mcp-client',
            tokenId: 1,
            principal: $this->principal,
            allowedOperations: ['read'],
            allowedReadTools: ['optical.power_matrix.v1'],
            allowedPrepareActions: [],
            allowedStatusTools: [],
        );
        $this->assertFalse($policy->authorize($this->principal, 'mcp', $matrixDesc, $grant));

        $dispatcher = app(CapabilityDispatcher::class);
        $result = $dispatcher->dispatch(
            $this->principal,
            'sdk',
            'optical.power_matrix.v1',
            'read',
            ['sph' => '-2.00'],
        );
        $this->assertSame('ok', $result['status'] ?? null);
        $this->assertArrayHasKey('cells', $result['data'] ?? []);

        $metrics = app(BusinessWorkflowMetrics::class)->opticalSummary($this->principal);
        $this->assertSame(0, $metrics['deterministic_actions_model_usage']);
        $this->assertSame(0, $metrics['deterministic_actions_credits']);
    }
}
