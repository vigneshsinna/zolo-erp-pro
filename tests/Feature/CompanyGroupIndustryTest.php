<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Platform\CapabilityService;
use App\Services\Platform\CompanyDirectoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\OperationsTestCase;

class CompanyGroupIndustryTest extends OperationsTestCase
{
    public function test_new_group_company_gets_its_industry_preset_and_business_type(): void
    {
        if (!Schema::hasTable('company_setup_audits')) {
            (require database_path('migrations/2026_10_09_000001_create_company_setup_audits.php'))->up();
        }
        (require database_path('migrations/2026_10_13_000001_create_company_groups.php'))->up();
        foreach (['address' => fn (Blueprint $t) => $t->text('address')->nullable(), 'is_active' => fn (Blueprint $t) => $t->boolean('is_active')->nullable()] as $column => $add) {
            if (!Schema::hasColumn('warehouses', $column)) {
                Schema::table('warehouses', $add);
            }
        }
        $this->actingAs(User::findOrFail(1));
        $directory = app(CompanyDirectoryService::class);
        $group = $directory->createGroup(1, ['name' => 'MJ Group']);

        $fmcg = $directory->createCompany(1, ['company_group_id' => $group->id, 'legal_name' => 'Motumo Foods Pvt Ltd',
            'trade_name' => 'Motumo FMCG', 'industry' => 'fmcg', 'subtype' => 'manufacturer',
            'timezone' => 'Asia/Kolkata', 'fy_start' => '2026-04-01', 'fy_end' => '2027-03-31']);

        $settings = DB::table('company_industry_settings')->where('company_id', $fmcg->id)->first();
        $this->assertSame(['fmcg', 'manufacturer'], [$settings->profile_key, $settings->subtype]);
        $capabilities = app(CapabilityService::class);
        $this->assertTrue($capabilities->enabled('inventory.batch_expiry', $fmcg->id));
        $this->assertTrue($capabilities->enabled('manufacturing.production', $fmcg->id));
        $this->assertSame(0, DB::table('company_industry_settings')->where('company_id', $this->company->id)
            ->where('profile_key', 'fmcg')->count(), 'Other companies keep their own profile.');
    }
}
