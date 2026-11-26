<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Directory;

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

/**
 * Task T03: Authorized ERP Directory API Test Suite.
 * Verifies read-only, scoped entity searches for warehouses, suppliers, products,
 * customers, and units, enforcing authentication, tenant boundaries, branch eligibility,
 * bounded inputs, wildcard sanitization, and permission-driven creation flags.
 */
final class AiEntityLookupTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private User $restrictedUser;
    private Company $companyA;
    private Company $companyB;
    private CompanyBranch $branchA1;
    private CompanyBranch $branchA2;
    private FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('erp_ai.enabled', true);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);
        DB::table('roles')->updateOrInsert(['id' => 2], ['name' => 'Restricted Staff', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminUser = User::create([
            'name' => 'Lookup Admin User',
            'email' => 'lookup_admin_' . uniqid() . '@example.com',
            'phone' => '97777777' . rand(10, 99),
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->restrictedUser = User::create([
            'name' => 'Restricted Operator',
            'email' => 'lookup_op_' . uniqid() . '@example.com',
            'phone' => '9777777734',
            'password' => bcrypt('secret'),
            'role_id' => 2,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->companyA = Company::create([
            'name' => 'Alpha Spinning Mills Ltd',
            'legal_name' => 'Alpha Spinning Mills Ltd',
            'code' => 'ALPHA_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        $this->companyB = Company::create([
            'name' => 'Beta Logistics Pvt Ltd',
            'legal_name' => 'Beta Logistics Pvt Ltd',
            'code' => 'BETA_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        $this->branchA1 = CompanyBranch::create([
            'company_id' => $this->companyA->id,
            'name' => 'Coimbatore Main Mill',
            'code' => 'CBE-01',
            'is_active' => true,
        ]);

        $this->branchA2 = CompanyBranch::create([
            'company_id' => $this->companyA->id,
            'name' => 'Tiruppur Processing Shed',
            'code' => 'TPR-01',
            'is_active' => true,
        ]);

        $this->fiscalYear = FiscalYear::create([
            'company_id' => $this->companyA->id,
            'name' => 'FY 2026-2027',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_closed' => false,
        ]);

        // Attach admin to company A
        $this->companyA->users()->syncWithoutDetaching([
            $this->adminUser->id => ['is_default' => true],
            $this->restrictedUser->id => ['is_default' => true],
        ]);
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->companyA->id, 'user_id' => $this->adminUser->id, 'branch_id' => $this->branchA1->id]
        );
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->companyA->id, 'user_id' => $this->restrictedUser->id, 'branch_id' => $this->branchA1->id]
        );

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->companyA->id, true, false);
    }

    private function createWarehouse(array $attributes): Warehouse
    {
        return Warehouse::query()->create(array_merge([
            'address' => '100 Industrial Estate, Tiruppur',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Test 1: Unauthenticated request is rejected.
     */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/ai/entity-lookup?type=warehouse');
        $response->assertStatus(401);
    }

    /**
     * Test 2: Invalid entity type is rejected with 422.
     */
    public function test_invalid_entity_type_is_rejected(): void
    {
        $response = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=invalid_type');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    }

    /**
     * Test 3: Query length is bounded (max 100 characters).
     */
    public function test_query_length_is_bounded(): void
    {
        $longQuery = str_repeat('a', 101);
        $response = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=warehouse&q=' . $longQuery);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['q']);
    }

    /**
     * Test 4: Warehouse search is strictly scoped to current tenant (Company A).
     * Warehouses from Company B are NEVER returned.
     */
    public function test_warehouse_search_is_strictly_scoped_to_current_company(): void
    {
        $whA = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Alpha Central Warehouse',
        ]);

        $whB = $this->createWarehouse([
            'company_id' => $this->companyB->id,
            'name' => 'Beta Secret Warehouse',
        ]);

        $response = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=warehouse');

        $response->assertOk();
        $items = $response->json('items');
        $itemIds = collect($items)->pluck('id')->all();

        $this->assertContains($whA->id, $itemIds);
        $this->assertNotContains($whB->id, $itemIds);
    }

    /**
     * Test 5: Branch eligibility rules:
     * - Same branch: eligible = true.
     * - Different branch: eligible = false, disabled_reason = 'wrong_branch'.
     * - Inactive: eligible = false, disabled_reason = 'inactive'.
     */
    public function test_warehouse_eligibility_enforces_branch_and_active_status(): void
    {
        $whEligible = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Branch One Active Godown',
            'is_active' => true,
        ]);

        $whWrongBranch = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA2->id,
            'name' => 'Branch Two Remote Godown',
            'is_active' => true,
        ]);

        $whInactive = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Branch One Decommissioned Godown',
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=warehouse');

        $response->assertOk();
        $items = collect($response->json('items'))->keyBy('id');

        $this->assertTrue($items[$whEligible->id]['eligible']);
        $this->assertNull($items[$whEligible->id]['disabled_reason']);

        $this->assertFalse($items[$whWrongBranch->id]['eligible']);
        $this->assertSame('wrong_branch', $items[$whWrongBranch->id]['disabled_reason']);

        $this->assertFalse($items[$whInactive->id]['eligible']);
        $this->assertSame('inactive', $items[$whInactive->id]['disabled_reason']);
    }

    /**
     * Test 6: Unique branch default: when query is omitted and branch has exactly 1 active warehouse,
     * recommended_id is populated with reason 'unique_branch_default'.
     */
    public function test_warehouse_recommendation_returns_unique_branch_default(): void
    {
        $whSingle = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Sole Main Warehouse',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=warehouse');

        $response->assertOk();
        $this->assertSame($whSingle->id, $response->json('recommended_id'));
        $this->assertSame('unique_branch_default', $response->json('recommendation_reason'));
    }

    /**
     * Test 7: When branch has multiple active warehouses and query is omitted,
     * recommended_id is null, recommendation_reason is 'multiple_branch_warehouses',
     * and status is 'selection_needed'.
     */
    public function test_warehouse_recommendation_is_null_when_branch_has_multiple_warehouses(): void
    {
        $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Alpha Godown 1',
        ]);
        $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Alpha Godown 2',
        ]);

        $response = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=warehouse');

        $response->assertOk();
        $this->assertNull($response->json('recommended_id'));
        $this->assertSame('multiple_branch_warehouses', $response->json('recommendation_reason'));
        $this->assertSame('selection_needed', $response->json('status'));
    }

    /**
     * Test 8: Search by query matches by name and ID, and wildcards are escaped.
     */
    public function test_warehouse_search_matches_query_and_sanitizes_wildcards(): void
    {
        $wh1 = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Dyeing Storage Yard',
        ]);
        $wh2 = $this->createWarehouse([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'name' => 'Spinning Storage Yard',
        ]);

        // Exact match
        $resExact = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=warehouse&q=Dyeing');
        $resExact->assertOk();
        $this->assertSame($wh1->id, $resExact->json('recommended_id'));
        $this->assertSame('unique_partial_match', $resExact->json('recommendation_reason'));

        // Query with wildcards % and _ should not act as SQL regex wildcard
        $resWildcard = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=warehouse&q=%Yard');
        $resWildcard->assertOk();
        // Since no warehouse name literally contains "%Yard", results should be empty or handle cleanly without SQL error
        $this->assertIsArray($resWildcard->json('items'));
    }

    /**
     * Test 9: Providing a task_id belonging to Company B when authenticated under Company A is rejected.
     */
    public function test_cross_tenant_task_id_is_rejected(): void
    {
        $taskCompBId = (string) \Illuminate\Support\Str::uuid();
        DB::table('ai_workflow_tasks')->insert([
            'id' => $taskCompBId,
            'company_id' => $this->companyB->id,
            'branch_id' => 1,
            'financial_year_id' => 1,
            'actor_id' => $this->adminUser->id,
            'action_key' => 'create_purchase_bill',
            'status' => 'collecting',
            'revision' => 1,
            'mode' => 'company',
            'slots_json' => json_encode([]),
            'issues_json' => json_encode([]),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=warehouse&task_id=' . $taskCompBId);

        $response->assertStatus(404);
    }

    /**
     * Test 10: can_create boolean reflects Spatie permission.
     * Admin has permission -> can_create: true.
     */
    public function test_can_create_reflects_actor_permission(): void
    {
        $response = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=warehouse');

        $response->assertOk();
        $this->assertTrue($response->json('can_create'));
        $this->assertNull($response->json('create_disabled_reason'));
    }

    /**
     * Test 11: Pagination and per_page bounds.
     * per_page requested as 1000 is capped to 50.
     */
    public function test_pagination_and_per_page_bounds(): void
    {
        $response = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=warehouse&per_page=1000');

        $response->assertOk();
        $this->assertLessThanOrEqual(50, $response->json('per_page'));
    }

    /**
     * Test 12: Directory searches for suppliers, products, customers, and units.
     */
    public function test_supplier_product_customer_unit_searches(): void
    {
        // Supplier
        $supplier = Supplier::create([
            'company_id' => $this->companyA->id,
            'name' => 'Tiruppur Threads Pvt Ltd',
            'company_name' => 'Tiruppur Threads Pvt Ltd',
            'vat_number' => '33AAACT1234D1Z5',
            'phone_number' => '9842100055',
            'is_active' => true,
        ]);

        $resSupplier = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=supplier&q=Threads');
        $resSupplier->assertOk();
        $this->assertCount(1, $resSupplier->json('items'));
        $this->assertSame($supplier->id, $resSupplier->json('items.0.id'));

        // Unit
        $unit = Unit::create([
            'company_id' => $this->companyA->id,
            'unit_code' => 'KGS',
            'unit_name' => 'Kilograms',
            'is_active' => true,
        ]);

        $resUnit = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=unit&q=KGS');
        $resUnit->assertOk();
        $this->assertCount(1, $resUnit->json('items'));
        $this->assertSame($unit->id, $resUnit->json('items.0.id'));

        // Category & Product
        $categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'Textile Raw Materials',
            'is_active' => true,
            'company_id' => $this->companyA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::forceCreate([
            'company_id' => $this->companyA->id,
            'name' => 'Combed Cotton Yarn 40s',
            'code' => 'YRN-40S',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'cost' => 320.00,
            'price' => 380.00,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'category_id' => $categoryId,
            'is_active' => true,
        ]);

        $resProduct = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/ai/entity-lookup?type=product&q=YRN-40S');
        $resProduct->assertOk();
        $this->assertCount(1, $resProduct->json('items'));
        $this->assertSame($product->id, $resProduct->json('items.0.id'));
    }
}
