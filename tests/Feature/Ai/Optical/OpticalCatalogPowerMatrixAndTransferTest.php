<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Optical;

use App\Ai\Automation\OpticalDeterministicTriggers;
use App\Ai\Knowledge\IndustryContextResolver;
use App\Ai\Knowledge\ScopedBusinessFactReader;
use App\Ai\Services\BatchRecordResolutionService;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\StockLine;
use App\Services\Inventory\StockMovementCommand;
use App\Services\Optical\OpticalCatalogService;
use App\Services\Optical\OpticalPowerMatrixService;
use App\Services\Optical\OpticalTransferPolicyService;
use App\Services\Platform\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class OpticalCatalogPowerMatrixAndTransferTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private Company $otherCompany;
    private CompanyBranch $branchA;
    private CompanyBranch $branchB;
    private Warehouse $whA;
    private Warehouse $whB;
    private User $actor;
    private CompanyContext $context;
    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_business_knowledge.enabled' => true,
            'ai_business_knowledge.opticals_enabled' => true,
            'ai_business_knowledge.opticals_cross_store_enabled' => true,
            'ai_actions.foundation_enabled' => true,
            'erp_ai.enabled' => true,
        ]);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->company = Company::create([
            'legal_name' => 'Vision Opticals Ltd',
            'trade_name' => 'Vision Opticals',
            'code' => 'OPT_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);
        $this->otherCompany = Company::create([
            'legal_name' => 'General Textile Co',
            'trade_name' => 'General Textile',
            'code' => 'GEN_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        DB::table('company_industry_settings')->insert([
            'company_id' => $this->company->id,
            'profile_key' => 'opticals',
            'subtype' => 'wholesale_distributor',
            'settings_json' => json_encode(['optical_defaults' => true]),
            'updated_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->branchA = $this->company->branches()->create(['code' => 'MAIN', 'name' => 'Chennai Central', 'branch_type' => 'branch']);
        $this->branchB = $this->company->branches()->create(['code' => 'SOUTH', 'name' => 'Madurai Store', 'branch_type' => 'branch']);
        $fy = $this->company->fiscalYears()->create([
            'name' => 'FY 2026-27',
            'code' => 'FY2627_' . uniqid(),
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_default' => true,
        ]);

        $this->whA = Warehouse::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'name' => 'Chennai Central WH',
            'address' => 'Chennai Central Street',
            'is_active' => true,
        ]);
        $this->whB = Warehouse::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchB->id,
            'name' => 'Madurai Store WH',
            'address' => 'Madurai South Street',
            'is_active' => true,
        ]);

        $this->actor = User::forceCreate([
            'name' => 'Optical Admin',
            'email' => 'opt_admin_' . uniqid() . '@example.test',
            'phone' => '9800000001',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->company->users()->attach($this->actor->id);
        DB::table('company_user_branches')->insert([
            ['company_id' => $this->company->id, 'user_id' => $this->actor->id, 'branch_id' => $this->branchA->id],
            ['company_id' => $this->company->id, 'user_id' => $this->actor->id, 'branch_id' => $this->branchB->id],
        ]);
        $this->actingAs($this->actor);

        $this->context = new CompanyContext($this->company->id, $this->branchA->id, $fy->id);

        $this->unitId = Unit::forceCreate([
            'company_id' => $this->company->id,
            'unit_name' => 'Piece',
            'unit_code' => 'PCS_' . uniqid(),
            'is_active' => true,
        ])->id;
    }

    private function makeLensProduct(string $code, string $name): Product
    {
        $categoryId = \App\Models\Category::firstOrCreate(
            ['company_id' => $this->company->id, 'name' => 'Optical Lenses'],
            ['is_active' => true]
        )->id;

        return Product::forceCreate([
            'company_id' => $this->company->id,
            'code' => $code . '_' . uniqid(),
            'name' => $name,
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $categoryId,
            'unit_id' => $this->unitId,
            'sale_unit_id' => $this->unitId,
            'purchase_unit_id' => $this->unitId,
            'cost' => 250,
            'price' => 450,
            'qty' => 0,
            'alert_quantity' => 5,
            'is_active' => true,
        ]);
    }

    public function test_exact_vs_candidate_resolution_never_substitutes_sign_or_step_and_matrix_reconciles_stock(): void
    {
        $catalog = app(OpticalCatalogService::class);
        $pMinus2 = $this->makeLensProduct('LENS-M2', 'Essilor 1.67 -2.00/-0.50');
        $pPlus2 = $this->makeLensProduct('LENS-P2', 'Essilor 1.67 +2.00/-0.50');

        $catalog->registerIdentity($this->context, $pMinus2, [
            'lens_family' => 'Essilor 1.67',
            'coating' => 'BlueCut',
            'sph' => '-2.00',
            'cyl' => '-0.50',
            'axis' => 90,
            'lr' => 'RE',
        ]);
        $catalog->registerIdentity($this->context, $pPlus2, [
            'lens_family' => 'Essilor 1.67',
            'coating' => 'BlueCut',
            'sph' => '+2.00',
            'cyl' => '-0.50',
            'axis' => 90,
            'lr' => 'RE',
        ]);

        app(InventoryMovementService::class)->receive(new StockMovementCommand(
            date: now()->toDateString(),
            lines: [new StockLine(
                productId: $pMinus2->id,
                qty: 10,
                warehouseId: $this->whA->id,
                unitCost: 250,
                uomId: $this->unitId,
            )],
            warehouseId: $this->whA->id,
            sourceType: 'opening_balance',
            sourceId: 1,
            idempotencyKey: 'opt-open-' . uniqid(),
            userId: $this->actor->id,
            context: $this->context,
        ));
        $pMinus2->forceFill(['qty' => 10])->save();

        $exact = $catalog->resolveExactOrCandidates($this->context, 'Essilor 1.67', [
            'sph' => '-2',
            'cyl' => '-0.5',
            'axis' => '90',
            'lr' => 'R',
            'coating' => 'BlueCut',
        ]);
        $this->assertSame('exact', $exact['status']);
        $this->assertSame($pMinus2->id, $exact['product']->id);

        // Wrong/unregistered power (-2.25) MUST NEVER substitute -2.00
        $wrongPower = $catalog->resolveExactOrCandidates($this->context, 'Essilor 1.67', [
            'sph' => '-2.25',
            'cyl' => '-0.50',
            'axis' => 90,
            'lr' => 'RE',
            'coating' => 'BlueCut',
        ]);
        $this->assertSame('none', $wrongPower['status']);
        $this->assertNull($wrongPower['product']);

        // Matching power with different/unmatched coating returns candidates with diff
        $near = $catalog->resolveExactOrCandidates($this->context, 'Essilor 1.67', [
            'sph' => '-2.00',
            'cyl' => '-0.50',
            'axis' => 90,
            'lr' => 'RE',
            'coating' => 'Photochromic',
        ]);
        $this->assertSame('candidates', $near['status']);
        $this->assertNull($near['product']);
        $this->assertNotEmpty($near['candidates']);
        $this->assertArrayHasKey('coating', $near['candidates'][0]['diff_fields']);

        $batchPrincipal = new \App\Ai\DTOs\AiPrincipal(
            actorId: $this->actor->id,
            companyId: $this->company->id,
            branchId: $this->branchA->id,
            financialYearId: $this->context->financialYearId,
            permittedToolNames: [],
        );
        $resolvedBatch = app(BatchRecordResolutionService::class)->resolveAll(
            new \App\Ai\DTOs\WorkflowTaskContext(principal: $batchPrincipal, context: $this->context),
            ['items' => [[
                'product_query' => 'Essilor 1.67',
                'optical_spec' => [
                    'lens_family' => 'Essilor 1.67',
                    'coating' => 'BlueCut',
                    'sph' => '-2.00',
                    'cyl' => '-0.50',
                    'axis' => 90,
                    'lr' => 'RE',
                ],
                'qty' => 2,
            ]]]
        );
        $this->assertSame($pMinus2->id, $resolvedBatch->resolvedIds['items[line-0].product_id'] ?? null);

        $matrix = app(OpticalPowerMatrixService::class)->powerMatrix($this->context, $this->actor->id, [
            'sph' => '-2.00',
            'include_authorized_stores' => true,
        ]);
        $this->assertSame(0, $matrix['model_calls']);
        $this->assertSame(10.0, (float) ($matrix['cells'][0]['stores'][0]['on_hand'] ?? 0));

        $repl = app(OpticalPowerMatrixService::class)->suggestReplenishment($this->context, $this->actor->id, []);
        $this->assertSame(0, $repl['model_calls']);
        $this->assertNotEmpty($repl['suggestions']);

        $triggers = app(OpticalDeterministicTriggers::class)->evaluate($this->context, $this->actor->id);
        $this->assertTrue($triggers['enabled']);
        $this->assertSame(0, $triggers['model_calls']);
        $this->assertNotEmpty($triggers['triggers']['low_exact_power_stock']);
    }

    public function test_same_gstin_transfer_allowed_and_cross_gstin_internal_transfer_blocked(): void
    {
        $policy = app(OpticalTransferPolicyService::class);

        $sameGstin = $policy->evaluate($this->context, $this->actor->id, $this->whA->id, $this->whB->id);
        $this->assertTrue($sameGstin['allowed']);

        DB::table('tax_registrations')->insert([
            [
                'company_id' => $this->company->id,
                'branch_id' => $this->branchA->id,
                'gstin' => '33AAACV1234A1Z5',
                'legal_name' => 'Vision TN',
                'state_code' => '33',
                'status' => 'active',
                'effective_from' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $this->company->id,
                'branch_id' => $this->branchB->id,
                'gstin' => '29AAACV1234A1Z4',
                'legal_name' => 'Vision KA',
                'state_code' => '29',
                'status' => 'active',
                'effective_from' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $cross = $policy->evaluate($this->context, $this->actor->id, $this->whA->id, $this->whB->id);
        $this->assertFalse($cross['allowed']);
        $this->assertSame('cross_gst_registration_requires_tax_route', $cross['reason']);
    }

    public function test_industry_context_and_scoped_fact_reader_block_non_optical_tenant_leakage(): void
    {
        $otherBranch = $this->otherCompany->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'branch_type' => 'branch']);
        $otherFy = $this->otherCompany->fiscalYears()->create([
            'name' => 'FY 2026-27',
            'code' => 'FY_OTH_' . uniqid(),
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_default' => true,
        ]);
        $otherCtx = new CompanyContext($this->otherCompany->id, $otherBranch->id, $otherFy->id);

        $resolver = app(IndustryContextResolver::class);
        $this->assertTrue($resolver->isOptical($this->context));
        $this->assertFalse($resolver->hasLabCapability($this->context));
        $this->assertFalse($resolver->isOptical($otherCtx));

        $p = $this->makeLensProduct('LENS-ISO', 'Isolated Lens');
        $identity = app(OpticalCatalogService::class)->registerIdentity($this->context, $p, ['sph' => '-1.00', 'cyl' => '0.00', 'lr' => 'RE']);

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);
        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->otherCompany->id, true, false);

        $reader = app(ScopedBusinessFactReader::class);
        $optPrincipal = new \App\Ai\DTOs\AiPrincipal(
            actorId: $this->actor->id,
            companyId: $this->company->id,
            branchId: $this->branchA->id,
            financialYearId: $this->context->financialYearId,
            permittedToolNames: [],
        );
        $fact = $reader->find($optPrincipal, 'optical_lens_identity', (int) $identity->id);
        $this->assertSame((int) $identity->id, $fact['facts']['id']);

        $otherActor = User::forceCreate([
            'name' => 'Other Co User',
            'email' => 'oth_' . uniqid() . '@example.test',
            'phone' => '9800000099',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->otherCompany->users()->attach($otherActor->id);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->otherCompany->id,
            'user_id' => $otherActor->id,
            'branch_id' => $otherBranch->id,
        ]);
        $otherPrincipal = new \App\Ai\DTOs\AiPrincipal(
            actorId: $otherActor->id,
            companyId: $this->otherCompany->id,
            branchId: $otherBranch->id,
            financialYearId: $otherFy->id,
            permittedToolNames: [],
        );

        $this->expectException(AuthorizationException::class);
        $reader->find($otherPrincipal, 'optical_lens_identity', (int) $identity->id);
    }
}
