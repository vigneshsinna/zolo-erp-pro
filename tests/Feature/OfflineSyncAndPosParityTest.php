<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OfflineSyncAndPosParityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(\App\Services\Platform\CapabilityService::class, fn () => new class extends \App\Services\Platform\CapabilityService {
            public function enabled(string $key, $company = null): bool
            {
                return true;
            }
        });

        // Ensure companies 2 and 3, branches, and fiscal years exist inside transaction for cross-vertical parity testing
        DB::table('companies')->updateOrInsert(['id' => 2], [
            'code' => 'TMWS',
            'legal_name' => 'TMWS Water Tankers',
            'trade_name' => 'TMWS',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
            'status' => 'active',
        ]);
        DB::table('companies')->updateOrInsert(['id' => 3], [
            'code' => 'DKW',
            'legal_name' => 'DK Waters',
            'trade_name' => 'DK Waters',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
            'status' => 'active',
        ]);

        if (Schema::hasTable('company_branches')) {
            DB::table('company_branches')->updateOrInsert(['id' => 2], [
                'company_id' => 2,
                'code' => 'TMWS-MAIN',
                'name' => 'TMWS Main Branch',
                'is_active' => true,
            ]);
            DB::table('company_branches')->updateOrInsert(['id' => 3], [
                'company_id' => 3,
                'code' => 'DKW-MAIN',
                'name' => 'DK Waters Main Branch',
                'is_active' => true,
            ]);
        }

        if (Schema::hasTable('fiscal_years')) {
            foreach ([2, 3] as $cid) {
                if (!DB::table('fiscal_years')->where('company_id', $cid)->exists()) {
                    DB::table('fiscal_years')->insert([
                        'company_id' => $cid,
                        'name' => 'FY 2026-27',
                        'start_date' => '2026-04-01',
                        'end_date' => '2027-03-31',
                        'is_closed' => 0,
                        'status' => 'open',
                    ]);
                }
            }
        }

        $admin = User::where('name', 'admin')->first() ?? User::find(1);
        if ($admin) {
            foreach ([1, 2, 3, 4] as $cid) {
                DB::table('company_user')->updateOrInsert(['company_id' => $cid, 'user_id' => $admin->id], [
                    'is_default' => $cid === 1 ? 1 : 0,
                ]);
            }
            if (Schema::hasTable('company_user_branches')) {
                DB::table('company_user_branches')->where('company_id', 1)->where('user_id', $admin->id)->delete();
                DB::table('company_user_branches')->insert(['company_id' => 1, 'user_id' => $admin->id, 'branch_id' => 1]);
                DB::table('company_user_branches')->updateOrInsert(['company_id' => 2, 'user_id' => $admin->id, 'branch_id' => 2], []);
                DB::table('company_user_branches')->updateOrInsert(['company_id' => 3, 'user_id' => $admin->id, 'branch_id' => 3], []);
            }
        }

        if (Schema::hasTable('water_tankers') && !DB::table('water_tankers')->where('id', 1)->exists()) {
            DB::table('water_tankers')->insert([
                'id' => 1,
                'vehicle_number' => 'TN-58-BW-2001',
                'model_type' => 'Tata LPT 2518',
                'capacity_kl' => 20,
                'capacity_liters' => 20000,
                'driver_name' => 'K. Murugan',
                'driver_phone' => '9842100001',
                'is_active' => true,
            ]);
        }

        if (Schema::hasTable('water_can_routes') && !DB::table('water_can_routes')->where('id', 1)->exists()) {
            DB::table('water_can_routes')->insert([
                'id' => 1,
                'route_name' => 'Route A - West Zone',
                'vehicle_no' => 'TN-58-WA-101',
                'driver_name' => 'driver',
                'driver_phone' => '9842100010',
                'daily_avg_cans' => 60,
                'is_active' => true,
            ]);
        }
    }

    public function test_cafe_pos_legacy_mobile_outbox_payload_syncs_and_updates_today_sales(): void
    {
        $user = User::where('name', 'admin')->first() ?? User::find(1);
        $token = $user->createToken('test-offline-sync')->plainTextToken;

        $cafeHeaders = [
            'Authorization' => "Bearer {$token}",
            'X-Company-ID' => '1',
            'X-Branch-ID' => '1',
        ];
        $beforeFin = $this->getJson('/api/v1/mobile/financials?sales_period=today', $cafeHeaders);

        $beforeFin->assertStatus(200);
        $beforeTodaySales = (float) ($beforeFin->json('data.sales_today') ?? 0);
        $beforeCash = (float) ($beforeFin->json('data.cash_balance') ?? 0);

        $legacyOutboxPayload = [
            'grand_total' => 450.0,
            'payment_mode' => 'Cash',
            'order_items' => [
                ['item_id' => 1, 'qty' => 2, 'price' => 150.0],
                ['item_id' => 2, 'qty' => 1, 'price' => 150.0],
            ],
            'client_timestamp' => now()->toIso8601String(),
        ];

        $syncRes = $this->postJson('/api/v1/cafe/orders', $legacyOutboxPayload, $cafeHeaders + [
            'Idempotency-Key' => 'win-outbox-pos-001',
        ]);

        $syncRes->assertStatus(201);
        $syncRes->assertJsonPath('success', true);
        $saleId = $syncRes->json('data.sale_id');
        $this->assertNotNull($saleId);

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'company_id' => 1,
            'branch_id' => 1,
            'grand_total' => 450.0,
            'paid_amount' => 450.0,
        ]);

        $afterFin = $this->getJson('/api/v1/mobile/financials?sales_period=today', $cafeHeaders);

        $afterFin->assertStatus(200);
        $afterTodaySales = (float) $afterFin->json('data.sales_today');
        $afterCash = (float) $afterFin->json('data.cash_balance');
        $this->assertEqualsWithDelta($beforeTodaySales + 450.0, $afterTodaySales, 0.01);
        $this->assertEqualsWithDelta($beforeCash + 450.0, $afterCash, 0.01);
    }

    public function test_all_mobile_outbox_endpoints_accept_offline_payload_schemas(): void
    {
        $user = User::where('name', 'admin')->first() ?? User::find(1);
        $token = $user->createToken('test-all-outbox')->plainTextToken;
        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Company-ID' => '3',
            'X-Branch-ID' => '3',
        ];

        // 1. Water Can Delivery mobile schema
        $this->postJson('/api/v1/can-deliveries', [
            'customer_name' => 'Walk-in Office',
            'delivered_cans' => 10,
            'returned_empties' => 8,
            'price_per_can' => 35.0,
            'total_amount' => 350.0,
            'payment_mode' => 'Cash',
            'client_timestamp' => now()->toIso8601String(),
        ], $headers)->assertStatus(201);

        // 2. Tanker Trip Sheet mobile schema
        $this->postJson('/api/v1/trip-sheets', [
            'tanker_id' => 1,
            'customer_name' => 'Surya Dyeing Works',
            'water_quantity_litres' => 12000,
            'start_odometer' => 45200,
            'end_odometer' => 45235,
            'motor_pumping_charge' => 250.0,
            'waiting_charge' => 100.0,
            'total_trip_amount' => 2850.0,
            'client_timestamp' => now()->toIso8601String(),
        ], ['Authorization' => "Bearer {$token}", 'X-Company-ID' => '2', 'X-Branch-ID' => '2'])->assertStatus(201);

        // 3. Payment collection with 'amount' key + cross-company customer rejection
        $co3CustomerId = DB::table('customers')->insertGetId([
            'customer_group_id' => 1,
            'name' => 'Test Co3 Customer',
            'company_name' => 'Test Co3 Customer',
            'phone_number' => '9842100099',
            'address' => 'Erode',
            'city' => 'Erode',
            'opening_balance' => 0,
            'is_active' => 1,
            'company_id' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/mobile/collect-payment', [
            'customer_id' => $co3CustomerId,
            'amount' => 500.0,
            'payment_mode' => 'UPI',
        ], $headers)->assertStatus(201);

        $co2CustomerId = DB::table('customers')->insertGetId([
            'customer_group_id' => 1,
            'name' => 'Test Co2 Foreign Customer',
            'company_name' => 'Test Co2 Foreign Customer',
            'phone_number' => '9842100088',
            'address' => 'Erode',
            'city' => 'Erode',
            'opening_balance' => 0,
            'is_active' => 1,
            'company_id' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/mobile/collect-payment', [
            'customer_id' => $co2CustomerId,
            'amount' => 100.0,
            'payment_mode' => 'Cash',
        ], $headers)->assertStatus(422);

        // 4. Quick Expense with 'category' string
        $this->postJson('/api/v1/expenses', [
            'category' => 'Fuel / Diesel',
            'amount' => 650.0,
            'note' => 'Van diesel refill',
        ], $headers)->assertStatus(201);

        // 5. Plant Intake with 'full_cans_loaded'
        $this->postJson('/api/v1/plant-intakes', [
            'vehicle_no' => 'TN-58-WA-101',
            'empty_cans_handed_over' => 40,
            'full_cans_loaded' => 40,
        ], $headers)->assertStatus(201);

        // 6. Spot Audit with 'physical_jars'
        $this->postJson('/api/v1/mobile/spot-audit', [
            'customer_name' => 'Muthu',
            'system_jars' => 60,
            'physical_jars' => 58,
            'remarks' => 'Checkpoint check',
        ], $headers)->assertStatus(201);

        // 7. Driver Surrender with 'net_cash_surrendered'
        $this->postJson('/api/v1/mobile/driver-surrender', [
            'net_cash_surrendered' => 1500.0,
            'notes' => 'Evening surrender',
        ], $headers)->assertStatus(200);

        // 8. Inventory Stock Count endpoints
        $this->postJson('/api/v1/inventory/stock-count', [
            'items' => [['id' => 1, 'counted' => 25]],
        ], $headers)->assertStatus(201);

        // 9. Simplified flat /sales bill on Co 1
        $this->postJson('/api/v1/sales', [
            'customer_name' => 'Counter Walkin',
            'item_name' => 'Filter Coffee',
            'qty' => 2,
            'unit_price' => 45.0,
            'grand_total' => 90.0,
            'paid_amount' => 90.0,
            'payment_mode' => 'UPI',
            'client_timestamp' => now()->toIso8601String(),
        ], ['Authorization' => "Bearer {$token}", 'X-Company-ID' => '1', 'X-Branch-ID' => '1'])->assertStatus(201);
    }

    public function test_cashier_with_single_authorized_branch_posts_cafe_pos_without_branch_header_and_blocks_cross_company(): void
    {
        $cashier = User::where('name', 'cashier')->first();
        if (!$cashier) {
            $this->markTestSkipped('Seeded cashier user not present.');
        }

        // Normalize cashier's single authorized branch inside transaction
        DB::table('company_user_branches')->where('user_id', $cashier->id)->delete();
        DB::table('company_user_branches')->insert([
            'company_id' => 1,
            'user_id' => $cashier->id,
            'branch_id' => 1,
        ]);

        $cashierToken = $cashier->createToken('test-cashier-branch')->plainTextToken;
        $cashierRes = $this->postJson('/api/v1/cafe/orders', [
            'grand_total' => 120.0,
            'payment_mode' => 'Cash',
            'order_items' => [['item_id' => 1, 'qty' => 1, 'price' => 120.0]],
        ], ['Authorization' => "Bearer {$cashierToken}", 'X-Company-ID' => '1']);

        $cashierRes->assertStatus(201);
        $this->assertDatabaseHas('sales', [
            'id' => $cashierRes->json('data.sale_id'),
            'user_id' => $cashier->id,
            'company_id' => 1,
            'branch_id' => 1,
        ]);

        $this->postJson('/api/v1/cafe/orders', [
            'grand_total' => 120.0,
            'payment_mode' => 'Cash',
        ], ['Authorization' => "Bearer {$cashierToken}", 'X-Company-ID' => '4'])->assertStatus(403);
    }
}

