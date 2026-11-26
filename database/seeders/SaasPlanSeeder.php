<?php

namespace Database\Seeders;

use App\Services\Platform\CapabilityCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SaasPlanSeeder extends Seeder
{
    public function run(): void
    {
        $core = array_keys(array_filter(
            CapabilityCatalog::DEFINITIONS,
            fn (array $definition) => (bool) ($definition[2] ?? false)
        ));

        $starter = array_values(array_unique([
            ...$core,
            'sales.pos',
            'inventory.lot_report',
            'communications.whatsapp',
            'sales.gift_cards',
            'sales.coupons',
        ]));

        $growth = array_values(array_unique([
            ...$starter,
            'inventory.multi_uom',
            'inventory.batch_expiry',
            'inventory.damage_stock',
            'sales.wholesale',
            'sales.exchange',
            'sales.catalogue_qr',
            'hrm.enabled',
            'operations.scheduling',
            'ai.copilot',
            'ai.documents',
            'ai.multimodal',
        ]));

        $industryPro = array_values(array_unique([
            ...$growth,
            'manufacturing.bom',
            'manufacturing.production',
            'operations.job_work',
            'operations.projects',
            'operations.installation',
            'inventory.serial_tracking',
            'inventory.dimension_tracking',
            'inventory.lot_tracking',
            'service.repair',
            'service.warranty_amc',
            'printing.dot_matrix',
            'sales.route_distribution',
            'sales.installment_plans',
            'integrations.api',
            'ai.actions',
            'ai.workflows',
            'ai.operations',
            'ai.schedules',
        ]));

        $all = array_keys(CapabilityCatalog::DEFINITIONS);

        $plans = [
            [
                'code' => 'trial',
                'name' => '14-Day SaaS Trial',
                'description' => 'Evaluation license with core ERP, POS, multi-UOM, WhatsApp, lot report, and read-only AI Copilot.',
                'billing_cycle' => 'trial',
                'price' => 0.00,
                'currency_code' => 'INR',
                'trial_days' => 14,
                'grace_days' => 3,
                'max_users' => 5,
                'max_branches' => 1,
                'max_warehouses' => 2,
                'max_monthly_invoices' => 250,
                'ai_monthly_credits' => 2000,
                'ai_monthly_media_pages' => 10,
                'ai_max_concurrent_runs' => 1,
                'ai_daily_token_limit' => 50000,
                'ai_read_only' => true,
                'modules_json' => json_encode(array_values(array_unique([...$core, 'sales.pos', 'inventory.multi_uom', 'inventory.lot_report', 'communications.whatsapp', 'ai.copilot']))),
                'is_active' => true,
                'is_system' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'starter',
                'name' => 'Starter ERP',
                'description' => 'Core commercial trading, GST, POS terminal, promotions, and WhatsApp (no AI add-ons).',
                'billing_cycle' => 'monthly',
                'price' => 1999.00,
                'currency_code' => 'INR',
                'trial_days' => 14,
                'grace_days' => 7,
                'max_users' => 10,
                'max_branches' => 2,
                'max_warehouses' => 3,
                'max_monthly_invoices' => 1500,
                'ai_monthly_credits' => 0,
                'ai_monthly_media_pages' => 0,
                'ai_max_concurrent_runs' => 1,
                'ai_daily_token_limit' => 0,
                'ai_read_only' => true,
                'modules_json' => json_encode($starter),
                'is_active' => true,
                'is_system' => true,
                'sort_order' => 2,
            ],
            [
                'code' => 'growth',
                'name' => 'Growth Business',
                'description' => 'Multi-UOM, batch/expiry, wholesale, exchange, QR catalogue, HRM, scheduling, and read-only AI Copilot + OCR.',
                'billing_cycle' => 'monthly',
                'price' => 4999.00,
                'currency_code' => 'INR',
                'trial_days' => 14,
                'grace_days' => 7,
                'max_users' => 30,
                'max_branches' => 5,
                'max_warehouses' => 10,
                'max_monthly_invoices' => 10000,
                'ai_monthly_credits' => 20000,
                'ai_monthly_media_pages' => 100,
                'ai_max_concurrent_runs' => 5,
                'ai_daily_token_limit' => 250000,
                'ai_read_only' => true,
                'modules_json' => json_encode($growth),
                'is_active' => true,
                'is_system' => true,
                'sort_order' => 3,
            ],
            [
                'code' => 'industry_pro',
                'name' => 'Industry & Manufacturing Pro',
                'description' => 'Manufacturing, job work, projects, serials, timber dimensions, repair, warranty/AMC, API, and AI Action Workflows.',
                'billing_cycle' => 'monthly',
                'price' => 9999.00,
                'currency_code' => 'INR',
                'trial_days' => 14,
                'grace_days' => 10,
                'max_users' => 100,
                'max_branches' => 20,
                'max_warehouses' => 30,
                'max_monthly_invoices' => 50000,
                'ai_monthly_credits' => 50000,
                'ai_monthly_media_pages' => 250,
                'ai_max_concurrent_runs' => 10,
                'ai_daily_token_limit' => 500000,
                'ai_read_only' => false,
                'modules_json' => json_encode($industryPro),
                'is_active' => true,
                'is_system' => true,
                'sort_order' => 4,
            ],
            [
                'code' => 'enterprise',
                'name' => 'Enterprise All-Modules',
                'description' => 'Full 45-module platform entitlement (all ERP verticals + all AI Operating Layer & MCP modules) and unlimited ERP quotas.',
                'billing_cycle' => 'yearly',
                'price' => 99999.00,
                'currency_code' => 'INR',
                'trial_days' => 14,
                'grace_days' => 15,
                'max_users' => null,
                'max_branches' => null,
                'max_warehouses' => null,
                'max_monthly_invoices' => null,
                'ai_monthly_credits' => 100000,
                'ai_monthly_media_pages' => 500,
                'ai_max_concurrent_runs' => 20,
                'ai_daily_token_limit' => 1000000,
                'ai_read_only' => false,
                'modules_json' => json_encode($all),
                'is_active' => true,
                'is_system' => true,
                'sort_order' => 5,
            ],
        ];

        $now = now();
        foreach ($plans as $plan) {
            $exists = DB::table('saas_plans')->where('code', $plan['code'])->exists();
            if ($exists) {
                DB::table('saas_plans')->where('code', $plan['code'])->update(
                    array_diff_key($plan, ['code' => true]) + ['updated_at' => $now]
                );
            } else {
                DB::table('saas_plans')->insert($plan + ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
