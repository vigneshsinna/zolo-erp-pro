<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Conversation;

use App\Ai\Services\ActionProposalService;
use App\Ai\Services\AiFeatureGate;
use App\Models\Biller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\SemanticAccountResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class UnifiedBusinessConversationTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private $branch;
    private $fiscalYear;
    private User $user;
    private Warehouse $warehouse;
    private Supplier $supplier;
    private Product $product;
    private Unit $meterUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'legal_name' => 'Unified Conversation Mill',
            'code' => 'UCM-'.Str::random(8),
            'status' => 'active',
            'country_code' => 'IN',
        ]);
        $this->branch = $this->company->branches()->create([
            'name' => 'Erode Branch',
            'code' => 'ERD',
            'is_active' => true,
        ]);
        $this->fiscalYear = $this->company->fiscalYears()->create([
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);

        $this->user = User::create([
            'name' => 'Unified AI Operator',
            'email' => 'operator-'.Str::uuid().'@example.test',
            'phone' => '9876500001',
            'password' => bcrypt('secret123'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company->users()->attach($this->user->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
        ]);

        // Permissions
        $adminRole = Role::firstOrCreate(['name' => 'Admin-'.Str::random(6), 'guard_name' => 'web', 'is_active' => true]);
        foreach ([
            'ai.use', 'customers-add', 'customers-index',
            'suppliers-add', 'suppliers-index',
            'purchases-add', 'purchases-index',
            'products-index', 'warehouse',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            $adminRole->givePermissionTo($perm);
        }
        $this->user->assignRole($adminRole);

        // Active Master Fixtures
        $this->meterUnit = Unit::create([
            'company_id' => $this->company->id,
            'unit_code' => 'MTR',
            'unit_name' => 'metres',
            'base_unit' => null,
            'operator' => '*',
            'operation_value' => 1.0,
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'name' => 'Erode Warehouse',
            'address' => 'Perundurai Road, Erode',
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'name' => 'New Trader',
            'company_name' => 'New Trader Textiles',
            'vat_number' => '33ABCDE1234F1Z5',
            'phone_number' => '9842000001',
            'email' => 'newtrader@example.test',
            'city' => 'Erode',
            'state' => 'Tamil Nadu',
            'address' => 'Brough Road',
            'is_active' => true,
        ]);

        $this->product = Product::forceCreate([
            'company_id' => $this->company->id,
            'name' => 'Linen Blue',
            'code' => 'SKU-LINEN-BLU',
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => 1,
            'unit_id' => $this->meterUnit->id,
            'purchase_unit_id' => $this->meterUnit->id,
            'sale_unit_id' => $this->meterUnit->id,
            'cost' => 140.0,
            'price' => 200.0,
            'is_active' => true,
        ]);

        Biller::forceCreate([
            'company_id' => $this->company->id,
            'name' => 'Main Biller',
            'company_name' => 'Unified Conversation Mill',
            'vat_number' => '33AAAAA0000A1Z5',
            'email' => 'biller@example.test',
            'phone_number' => '9842000000',
            'address' => 'Station Road',
            'city' => 'Erode',
            'state' => 'Tamil Nadu',
            'postal_code' => '638001',
            'country' => 'India',
            'is_active' => true,
        ]);

        DB::table('customer_groups')->updateOrInsert(
            ['company_id' => $this->company->id, 'name' => 'General'],
            ['percentage' => '0', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        config([
            'erp_ai.enabled' => true,
            'erp_ai.conversation_enabled' => true,
            'ai_actions.foundation_enabled' => true,
            'ai_actions.execution_enabled' => true,
            'ai_business_knowledge.enabled' => true,
            'ai_business_knowledge.semantic_enabled' => true,
            'ai_business_knowledge.planner_enabled' => true,
        ]);

        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, true);
        Http::preventStrayRequests();

        $this->actingAs($this->user)->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'financial_year_id' => $this->fiscalYear->id,
        ]);
    }

    public function test_party_direction_resolution_and_customer_creation_end_to_end(): void
    {
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');

        // Turn 1: Ambiguous party query (A03)
        $ask = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread,
            'message' => 'Create new party for buying linen',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk();

        $ask->assertJsonPath('workflow.question_type', 'party_direction');
        $this->assertStringContainsString('Will you buy from this party (supplier), or will the party buy from you (customer)?', $ask->json('workflow.message'));
        $this->assertSame(0, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());

        // Turn 2: Clarification specifies customer direction ("They buy from us")
        $reply = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread,
            'message' => 'They buy linen from us',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk();

        $reply->assertJsonPath('workflow.action_key', 'setup.customer.create.v1');
        $taskId = $reply->json('workflow.task_id');
        $this->assertNotEmpty($taskId);
        $this->assertStringContainsString('name', strtolower($reply->json('workflow.message')));

        // Turn 3: User provides name
        $nameTurn = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread,
            'message' => 'Acme Retailers',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk();

        $this->assertStringContainsString('contact', strtolower($nameTurn->json('workflow.message')));

        // Turn 4: User provides phone and city
        $detailsTurn = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread,
            'message' => 'phone 9876543210, city Tirupur',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk();

        // Turn 5: Preview customer proposal
        $previewTurn = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread,
            'message' => 'preview customer',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk();

        $previewTurn->assertJsonPath('workflow.status', 'proposal_created');
        $proposalId = $previewTurn->json('workflow.proposal_id');
        $this->assertNotEmpty($proposalId);

        // Assert proposal exists and is pending, but zero records written to customers table yet
        $this->assertSame(0, Customer::where('company_id', $this->company->id)->where('name', 'Acme Retailers')->count());

        // Turn 6: Explicit human approval executes CustomerSetupCommandAdapter
        $approve = $this->postJson(route('api.v1.ai.proposals.approve', $proposalId), [
            'expected_version' => 1,
        ])->assertOk();

        $approve->assertJsonPath('status', 'completed');

        // Customer must now be created in DB with party_tax_profiles
        $customer = Customer::where('company_id', $this->company->id)->where('name', 'Acme Retailers')->first();
        $this->assertNotNull($customer);
        $this->assertSame('9876543210', $customer->phone_number);
        $this->assertSame('Tirupur', $customer->city);

        Http::assertNothingSent();
    }

    public function test_create_customer_who_buys_linen_from_us_directly_starts_customer_flow(): void
    {
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');

        $turn = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread,
            'message' => 'Create a customer who buys linen from us',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk();

        $turn->assertJsonPath('workflow.action_key', 'setup.customer.create.v1');
        $this->assertStringContainsString('name', strtolower($turn->json('workflow.message')));
        Http::assertNothingSent();
    }

    public function test_single_turn_multi_field_purchase_order_and_natural_corrections(): void
    {
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');

        // Turn 1: Single multi-field sentence
        $turn1 = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread,
            'message' => 'Create a PO for 200 metres of Linen Blue from New Trader, delivered to Erode Warehouse.',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk();

        $taskId = $turn1->json('workflow.task_id');
        $this->assertNotEmpty($taskId);
        $this->assertSame('purchase_order.create.v1', $turn1->json('workflow.action_key'));

        // Check draft slots in database
        $draft = json_decode(DB::table('ai_workflow_tasks')->where('id', $taskId)->value('slots_json'), true);
        $this->assertSame('New Trader', $draft['supplier_query']);
        $this->assertSame('Erode Warehouse', $draft['warehouse_query']);
        $this->assertCount(1, $draft['items']);
        $this->assertEquals(200.0, (float) $draft['items'][0]['qty']);
        $this->assertSame('metres', $draft['items'][0]['uom']);
        $this->assertSame('Linen Blue', $draft['items'][0]['product_query']);

        // Turn 2: Natural correction: change qty to 180, split 20 on second line, specify cash/credit
        $turn2 = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread,
            'message' => 'Actually 180 metres and put 20 in a separate line. use credit 30 days',
            'client_turn_id' => (string) Str::uuid(),
            'active_task_id' => $taskId,
            'expected_task_revision' => (int) $turn1->json('workflow.task_revision'),
        ])->assertOk();

        $draft2 = json_decode(DB::table('ai_workflow_tasks')->where('id', $taskId)->value('slots_json'), true);
        $this->assertCount(2, $draft2['items']);
        $this->assertEquals(180.0, (float) $draft2['items'][0]['qty']);
        $this->assertEquals(20.0, (float) $draft2['items'][1]['qty']);
        $this->assertSame('credit', $draft2['payment_terms_type']);
        $this->assertSame(30, $draft2['credit_days']);

        Http::assertNothingSent();
    }

    public function test_unauthorized_customer_creation_fails_closed(): void
    {
        $unauthRole = Role::firstOrCreate(['name' => 'ReadOnly-'.Str::random(6), 'guard_name' => 'web', 'is_active' => true]);
        $unauthRole->givePermissionTo('ai.use');

        $unauthUser = User::create([
            'name' => 'Restricted Operator',
            'email' => 'ro-'.Str::uuid().'@example.test',
            'phone' => '9842099999',
            'password' => bcrypt('secret123'),
            'role_id' => $unauthRole->id,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->company->users()->attach($unauthUser->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $unauthUser->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->actingAs($unauthUser)->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'financial_year_id' => $this->fiscalYear->id,
        ]);

        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');

        $this->postJson(route('ai.chat'), [
            'thread_id' => $thread,
            'message' => 'Create a customer named Restricted Party',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_duplicate_customer_detection_blocks_preview(): void
    {
        Customer::create([
            'company_id' => $this->company->id,
            'customer_group_id' => 1,
            'name' => 'Duplicate Party',
            'tax_no' => '33DUPPA1234F1Z1',
            'phone_number' => '9842000000',
            'is_active' => true,
        ]);

        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');

        $start = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread,
            'message' => 'Create a customer named Duplicate Party',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk();

        $preview = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread,
            'message' => 'preview customer',
            'client_turn_id' => (string) Str::uuid(),
            'active_task_id' => $start->json('workflow.task_id'),
            'expected_task_revision' => (int) $start->json('workflow.task_revision'),
        ])->assertOk();

        $this->assertSame('needs_input', $preview->json('workflow.status'));
        $this->assertStringContainsString('matching customer already exists', $preview->json('workflow.message'));
        $this->assertNull($preview->json('workflow.proposal_id'));

        Http::assertNothingSent();
    }
}
