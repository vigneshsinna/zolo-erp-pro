<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Clarification;

use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class EntityOptionsTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;
    private Warehouse $warehouse1;
    private Warehouse $warehouse2;
    private Supplier $supplier1;
    private Product $product1;
    private Customer $customer1;
    private Unit $unit1;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('commercial.enabled', true);
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminUser = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'Entity Options Admin',
            'email' => 'entity_opt_admin_' . uniqid() . '@example.com',
            'phone' => '9888888801',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'TexPro Logistics Ltd',
            'code' => 'TEXPRO_OPT_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->branch = $this->company->branches()->where('code', 'MAIN')->first()
            ?? $this->company->branches()->create(['code' => 'MAIN', 'name' => 'Main Hub', 'branch_type' => 'branch']);

        $this->fiscalYear = $this->company->fiscalYears()->first()
            ?? $this->company->fiscalYears()->create([
                'name' => 'FY 2026-27',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
                'status' => 'open',
                'is_closed' => false,
            ]);

        $this->company->users()->syncWithoutDetaching([
            $this->adminUser->id => ['is_default' => true],
        ]);
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->adminUser->id, 'branch_id' => $this->branch->id]
        );

        $this->warehouse1 = Warehouse::create([
            'name' => 'North Central Depot',
            'address' => '42 Industrial Park, Sector 1',
            'is_active' => true,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->warehouse2 = Warehouse::create([
            'name' => 'South Transit Hub',
            'address' => '99 Railway Feeder, Sector 2',
            'is_active' => true,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->supplier1 = Supplier::create([
            'name' => 'Apex Raw Materials',
            'company_name' => 'Apex Raw Materials Pvt Ltd',
            'vat_number' => '33AAAAA1234A1Z5',
            'phone_number' => '9876543210',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $uniqueUnitCode = 'MTR_' . strtoupper(substr(uniqid(), 0, 4));
        $this->unit1 = Unit::forceCreate([
            'unit_code' => $uniqueUnitCode,
            'unit_name' => 'Custom Meter',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $category = \App\Models\Category::where('company_id', $this->company->id)->first()
            ?? \App\Models\Category::forceCreate(['name' => 'Textiles', 'company_id' => $this->company->id, 'is_active' => true]);

        $this->product1 = Product::forceCreate([
            'name' => 'Premium Grey Yarn 40s',
            'code' => 'YRN-40S-GREY',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $category->id,
            'cost' => 180.0,
            'price' => 240.0,
            'unit_id' => $this->unit1->id,
            'purchase_unit_id' => $this->unit1->id,
            'sale_unit_id' => $this->unit1->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->customer1 = Customer::forceCreate([
            'name' => 'Vignesh Global Exporters',
            'company_name' => 'Vignesh Global Exporters Ltd',
            'tax_no' => '33BBBBB5678B1Z9',
            'phone_number' => '9876500000',
            'customer_group_id' => 1,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);
    }

    private function contextHeaders(): array
    {
        return [
            'X-Company-Id' => (string) $this->company->id,
            'X-Branch-Id' => (string) $this->branch->id,
            'X-Financial-Year-Id' => (string) $this->fiscalYear->id,
        ];
    }

    public function test_warehouse_options_returns_branch_scoped_candidates_with_metadata(): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->getJson('/api/v1/ai/entity-options?type=warehouse', $this->contextHeaders());

        $response->assertStatus(200);
        $response->assertJson([
            'entity_type' => 'warehouse',
            'field' => 'warehouse_id',
            'status' => 'selection_needed',
            'has_more' => false,
            'can_create' => true,
        ]);

        $items = $response->json('items');
        $this->assertGreaterThanOrEqual(2, count($items));
        $names = collect($items)->pluck('name')->all();
        $this->assertContains('North Central Depot', $names);
        $this->assertContains('South Transit Hub', $names);

        $first = $items[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('branch_id', $first);
        $this->assertArrayHasKey('location', $first);
        $this->assertSame((int) $this->branch->id, (int) $first['branch_id']);
    }

    public function test_warehouse_options_filters_by_query_string(): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->getJson('/api/v1/ai/entity-options?type=warehouse&q=South', $this->contextHeaders());

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('South Transit Hub', $items[0]['name']);
        $this->assertSame((int) $this->warehouse2->id, (int) $response->json('recommended_id'));
        $this->assertSame('Single matching warehouse in active branch', $response->json('recommendation_reason'));
    }

    public function test_supplier_options_returns_company_suppliers(): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->getJson('/api/v1/ai/entity-options?type=supplier&q=Apex', $this->contextHeaders());

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame((int) $this->supplier1->id, (int) $items[0]['id']);
        $this->assertSame('Apex Raw Materials Pvt Ltd', $items[0]['name']);
        $this->assertSame('33AAAAA1234A1Z5', $items[0]['gstin']);
    }

    public function test_product_options_returns_company_products(): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->getJson('/api/v1/ai/entity-options?type=product&q=YRN-40S', $this->contextHeaders());

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame((int) $this->product1->id, (int) $items[0]['id']);
        $this->assertSame('Premium Grey Yarn 40s', $items[0]['name']);
        $this->assertSame(180.0, (float) $items[0]['cost']);
    }

    public function test_customer_options_returns_company_customers(): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->getJson('/api/v1/ai/entity-options?type=customer&q=Vignesh', $this->contextHeaders());

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame((int) $this->customer1->id, (int) $items[0]['id']);
    }

    public function test_unit_options_returns_active_units(): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->getJson('/api/v1/ai/entity-options?type=unit&q=' . $this->unit1->unit_code, $this->contextHeaders());

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame((int) $this->unit1->id, (int) $items[0]['id']);
    }

    public function test_entity_options_strictly_enforces_multi_tenant_isolation(): void
    {
        $otherCompany = Company::create([
            'name' => 'Foreign Corp Ltd',
            'legal_name' => 'Foreign Corp Ltd',
            'code' => 'FOREIGN_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);
        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($otherCompany->id, true, false);
        $otherBranch = $otherCompany->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'branch_type' => 'branch']);
        $otherWarehouse = Warehouse::create([
            'name' => 'Foreign Secret Depot',
            'address' => 'Private Road',
            'is_active' => true,
            'company_id' => $otherCompany->id,
            'branch_id' => $otherBranch->id,
        ]);

        $this->actingAs($this->adminUser);

        // When queried under $this->company, $otherWarehouse must never appear even if explicitly searched
        $response = $this->getJson('/api/v1/ai/entity-options?type=warehouse&q=Secret', $this->contextHeaders());

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('items'));
        $this->assertSame('none', $response->json('status'));
    }

    public function test_entity_options_unauthenticated_fails(): void
    {
        $response = $this->getJson('/api/v1/ai/entity-options?type=warehouse');
        $response->assertStatus(401);
    }
}
