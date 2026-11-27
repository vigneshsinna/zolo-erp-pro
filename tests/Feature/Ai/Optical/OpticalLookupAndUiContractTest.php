<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Optical;

use App\Models\Accounting\FiscalYear;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Optical\OpticalCatalogService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class OpticalLookupAndUiContractTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private int $branchId;
    private int $fyId;
    private User $admin;
    private Warehouse $warehouse;
    private Product $exactLens;
    private Product $mismatchLens;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('erp_ai.enabled', true);
        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_business_knowledge.enabled', true);
        config()->set('ai_business_knowledge.opticals_enabled', true);

        $this->company = Company::create([
            'legal_name' => 'Optical UX Store ' . uniqid(),
            'trade_name' => 'Optical UX Store',
            'code' => 'OUX' . strtoupper(substr(uniqid(), -5)),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
            'timezone' => 'Asia/Kolkata',
        ]);
        $this->branchId = $this->company->branches()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true])->id;
        $this->fyId = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'is_closed' => false,
        ])->id;

        DB::table('company_industry_settings')->insert([
            'company_id' => $this->company->id,
            'profile_key' => 'opticals',
            'subtype' => 'retail_and_wholesale',
            'settings_json' => json_encode(['lens_powers' => true]),
            'updated_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);
        $role = Role::findById(1, 'web');
        foreach (['ai.use', 'warehouse', 'products-add', 'sales-add'] as $p) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']));
        }
        $this->admin = User::create([
            'name' => 'Optical UX Admin',
            'email' => 'opt_ux_' . uniqid() . '@example.com',
            'phone' => '9800055566',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->admin->assignRole($role);
        DB::table('company_user')->insert(['company_id' => $this->company->id, 'user_id' => $this->admin->id, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_user_branches')->insert(['company_id' => $this->company->id, 'user_id' => $this->admin->id, 'branch_id' => $this->branchId, 'created_at' => now(), 'updated_at' => now()]);

        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'name' => 'Lens Store A',
            'address' => 'Chennai',
            'is_active' => true,
        ]);

        $unit = Unit::create(['company_id' => $this->company->id, 'unit_code' => 'PCS-' . uniqid(), 'unit_name' => 'Pieces', 'is_active' => true]);
        $cat = Category::create(['company_id' => $this->company->id, 'name' => 'Lenses', 'is_active' => true]);

        $this->exactLens = Product::forceCreate([
            'company_id' => $this->company->id,
            'name' => 'Essilor 1.56 HC -2.00',
            'code' => 'ESS-M200-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $cat->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => 300,
            'price' => 600,
            'qty' => 14,
            'is_active' => true,
        ]);
        $this->exactLens->company_id = $this->company->id;
        $this->exactLens->save();

        $this->mismatchLens = Product::forceCreate([
            'company_id' => $this->company->id,
            'name' => 'Essilor 1.56 HC +2.00',
            'code' => 'ESS-P200-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $cat->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => 300,
            'price' => 600,
            'qty' => 8,
            'is_active' => true,
        ]);
        $this->mismatchLens->company_id = $this->company->id;
        $this->mismatchLens->save();

        DB::table('product_warehouse')->insert([
            ['company_id' => $this->company->id, 'product_id' => $this->exactLens->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 14, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $this->company->id, 'product_id' => $this->mismatchLens->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 8, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $ctx = new CompanyContext($this->company->id, $this->branchId, $this->fyId);
        $catalog = app(OpticalCatalogService::class);
        $catalog->registerIdentity($ctx, $this->exactLens, [
            'lens_family' => 'Essilor 1.56 HC',
            'lens_form' => 'finished_stock',
            'sph' => '-2.00',
            'dia' => '70',
        ]);
        $catalog->registerIdentity($ctx, $this->mismatchLens, [
            'lens_family' => 'Essilor 1.56 HC',
            'lens_form' => 'finished_stock',
            'sph' => '+2.00',
            'dia' => '70',
        ]);
    }

    public function test_entity_options_enforces_exact_signed_optical_recommendation_with_stock_and_mismatch_badges(): void
    {
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];
        $resExact = $this->actingAs($this->admin)->withSession($sess)->getJson('/api/v1/ai/entity-options?' . http_build_query([
            'type' => 'product',
            'q' => 'Essilor 1.56 HC',
            'warehouse_id' => $this->warehouse->id,
            'optical_spec' => ['sph' => '-2.00', 'dia' => '70'],
        ]));

        $resExact->assertStatus(200);
        $this->assertSame($this->exactLens->id, $resExact->json('recommended_id'), 'Lookup response: ' . $resExact->getContent());
        $this->assertSame('exact_optical_match', $resExact->json('recommendation_reason'));

        $items = collect($resExact->json('items'));
        $matchedItem = $items->firstWhere('id', $this->exactLens->id);
        $this->assertNotNull($matchedItem);
        $this->assertSame('-2.00', $matchedItem['optical_spec']['sph'] ?? null);
        $this->assertNotNull($matchedItem['available_qty']);
        $this->assertNotEmpty($matchedItem['stock_as_of']);

        // Opposite sign (+2.00 vs -2.25) must NOT recommend wrong sign and must surface mismatch badges
        $resMismatch = $this->actingAs($this->admin)->withSession($sess)->getJson('/api/v1/ai/entity-options?' . http_build_query([
            'type' => 'product',
            'q' => 'Essilor 1.56 HC',
            'warehouse_id' => $this->warehouse->id,
            'optical_spec' => ['sph' => '-2.25', 'dia' => '70'],
        ]));

        $resMismatch->assertStatus(200);
        $this->assertNull($resMismatch->json('recommended_id'));
        $mismatchItems = collect($resMismatch->json('items'));
        $wrongItem = $mismatchItems->firstWhere('id', $this->exactLens->id);
        $this->assertNotEmpty($wrongItem['mismatch_badges']);
    }
}
