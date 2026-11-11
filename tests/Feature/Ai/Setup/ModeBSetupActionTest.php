<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Setup;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class ModeBSetupActionTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private Company $company;
    private $branch;
    private $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);
        $this->app->forgetInstance(\App\Ai\Services\AiFeatureGate::class);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminUser = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'Mode B Admin User',
            'email' => 'modeb_admin_' . uniqid() . '@example.com',
            'phone' => '9666666661',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'Mode B Setup Mills Ltd',
            'code' => 'MODEB_' . rand(1000, 9999),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->branch = $this->company->branches()->where('code', 'MAIN')->first()
            ?? $this->company->branches()->create(['code' => 'MAIN', 'name' => 'Main Branch', 'branch_type' => 'branch']);

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
    }

    private function contextHeaders(): array
    {
        return [
            'X-Company-Id' => (string) $this->company->id,
            'X-Branch-Id' => (string) $this->branch->id,
            'X-Financial-Year-Id' => (string) $this->fiscalYear->id,
        ];
    }

    public function test_unit_setup_creates_unit_atomically(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $uniqueCode = 'BALE_' . rand(100, 999);
        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'setup.unit.create.v1',
            'slots' => [
                'unit_code' => $uniqueCode,
                'unit_name' => 'Cotton Bale (170 KG)',
                'operator' => '*',
                'operation_value' => '170.00',
            ],
        ]);

        $response->assertStatus(201);
        $proposalId = $response->json('proposal.id');
        $this->assertSame('pending', $response->json('status'));
        $this->assertSame($uniqueCode, $response->json('proposal.preview.unit_code'));

        $approveResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $approveResponse->assertStatus(200);
        $this->assertSame('completed', $approveResponse->json('status'));

        $unit = Unit::where('company_id', $this->company->id)->where('unit_code', $uniqueCode)->first();
        $this->assertNotNull($unit);
        $this->assertSame('Cotton Bale (170 KG)', $unit->unit_name);
    }

    public function test_product_setup_creates_product_atomically(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $uniqueCode = 'RAW_COT_' . rand(100, 999);
        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'setup.product.create.v1',
            'slots' => [
                'name' => 'Raw Shankar-6 Cotton',
                'code' => $uniqueCode,
                'type' => 'standard',
                'cost' => '180.00',
                'price' => '210.00',
                'hsn_code' => '5201',
            ],
        ]);

        $response->assertStatus(201);
        $proposalId = $response->json('proposal.id');
        $this->assertSame($uniqueCode, $response->json('proposal.preview.code'));

        $approveResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $approveResponse->assertStatus(200);
        $product = Product::where('company_id', $this->company->id)->where('code', $uniqueCode)->first();
        $this->assertNotNull($product);
        $this->assertSame('Raw Shankar-6 Cotton', $product->name);
        $this->assertEquals(180.0, (float) $product->cost);
        $this->assertSame(0.0, (float) $product->qty);
    }

    public function test_supplier_setup_creates_supplier_and_tax_profile(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $uniqueName = 'Apex Ginning Mills ' . rand(100, 999);
        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'setup.supplier.create.v1',
            'slots' => [
                'name' => $uniqueName,
                'company_name' => $uniqueName . ' Pvt Ltd',
                'phone_number' => '9888877771',
                'vat_number' => '33AAACC1206D1ZN',
                'state' => 'Tamil Nadu',
                'city' => 'Coimbatore',
            ],
        ]);

        $response->assertStatus(201);
        $proposalId = $response->json('proposal.id');

        $approveResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $approveResponse->assertStatus(200);
        $supplier = Supplier::where('company_id', $this->company->id)->where('name', $uniqueName)->first();
        $this->assertNotNull($supplier);
        $this->assertSame('33AAACC1206D1ZN', $supplier->vat_number);

        $taxProfile = DB::table('party_tax_profiles')
            ->where('company_id', $this->company->id)
            ->where('party_type', 'supplier')
            ->where('party_id', $supplier->id)
            ->first();
        $this->assertNotNull($taxProfile);
        $this->assertSame('33AAACC1206D1ZN', $taxProfile->gstin);
    }

    public function test_customer_setup_creates_customer_and_tax_profile(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $uniqueName = 'Pallava Weaving Mills ' . rand(100, 999);
        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'setup.customer.create.v1',
            'slots' => [
                'name' => $uniqueName,
                'company_name' => $uniqueName . ' Ltd',
                'phone_number' => '9888877772',
                'tax_no' => '33AAACB1206D1ZM',
                'state' => 'Tamil Nadu',
                'city' => 'Erode',
            ],
        ]);

        $response->assertStatus(201);
        $proposalId = $response->json('proposal.id');

        $approveResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $approveResponse->assertStatus(200);
        $customer = Customer::where('company_id', $this->company->id)->where('name', $uniqueName)->first();
        $this->assertNotNull($customer);
        $this->assertSame('33AAACB1206D1ZM', $customer->tax_no);

        $taxProfile = DB::table('party_tax_profiles')
            ->where('company_id', $this->company->id)
            ->where('party_type', 'customer')
            ->where('party_id', $customer->id)
            ->first();
        $this->assertNotNull($taxProfile);
        $this->assertSame('33AAACB1206D1ZM', $taxProfile->gstin);
    }
}
