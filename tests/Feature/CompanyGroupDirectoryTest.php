<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveLegacyCompanyContext;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\Platform\CompanyDirectoryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CompanyContextTestCase;

class CompanyGroupDirectoryTest extends CompanyContextTestCase
{
    private CompanyDirectoryService $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAccountingFoundation();
        (require database_path('migrations/2026_10_09_000001_create_company_setup_audits.php'))->up();
        (require database_path('migrations/2026_10_13_000001_create_company_groups.php'))->up();
        Schema::table('warehouses', function (Blueprint $table) {
            $table->text('address')->nullable();
            $table->boolean('is_active')->nullable();
        });
        Schema::create('currencies', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code');
            $table->string('name');
        });
        Schema::create('general_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('currency')->nullable();
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 3, 'name' => 'Cashier', 'role_id' => 4]);
        $this->directory = app(CompanyDirectoryService::class);
    }

    private function newCompany(int $actor, ?int $groupId, string $name, string $industry = 'fmcg'): Company
    {
        $this->actingAs(User::findOrFail($actor));

        return $this->directory->createCompany($actor, ['company_group_id' => $groupId, 'legal_name' => $name.' Pvt Ltd',
            'trade_name' => $name, 'industry' => $industry, 'timezone' => 'Asia/Kolkata',
            'fy_start' => '2026-04-01', 'fy_end' => '2027-03-31']);
    }

    public function test_group_lists_member_companies_and_hides_others(): void
    {
        $group = $this->directory->createGroup(1, ['name' => 'MJ Group', 'company_ids' => [$this->company->id]]);

        $result = $this->directory->directory(1);
        $this->assertSame('MJ_GROUP', $group->code);
        $this->assertSame(['MJ Group'], array_column($result['groups'], 'name'));
        $this->assertSame([$this->company->id], array_column($result['groups'][0]['companies'], 'id'));
        $this->assertSame([], $result['ungrouped']);
        $this->assertSame(1, $result['count'], 'Company B is not a membership of user 1.');
    }

    public function test_new_company_in_group_is_ready_for_every_group_admin(): void
    {
        $group = $this->directory->createGroup(1, ['name' => 'MJ Group']);
        $this->directory->addGroupAdmin(1, $group->id, 2);
        $textile = $this->newCompany(1, $group->id, 'MJ Textiles', 'textile');

        $this->assertSame($group->id, $textile->company_group_id);
        $this->assertSame('MJ_TEXTILES', $textile->code);
        $branch = $textile->branches()->sole();
        $this->assertSame('MAIN', $branch->code);
        $this->assertSame('FY 2026-27', $textile->fiscalYears()->sole()->name);
        $this->assertSame(1, DB::table('warehouses')->where('company_id', $textile->id)->where('branch_id', $branch->id)->count());
        $this->assertSame(count(ChartOfAccountsSeeder::accounts()),
            ChartOfAccount::withoutGlobalScopes()->where('company_id', $textile->id)->count());
        $this->assertNotNull(DB::table('semantic_account_mappings')->where('company_id', $textile->id)
            ->where('semantic_role', 'ar')->value('account_id'));
        foreach ([1, 2] as $admin) {
            $context = $this->resolver->resolve($admin, $textile->id, $branch->id);
            $this->assertSame($textile->id, $context->companyId);
        }
        $this->assertSame('company_created', DB::table('company_setup_audits')->where('company_id', $textile->id)->value('action'));
    }

    public function test_group_admin_added_later_gains_existing_companies(): void
    {
        $group = $this->directory->createGroup(1, ['name' => 'MJ Group']);
        $timber = $this->newCompany(1, $group->id, 'MJ Timber', 'timber');

        $this->directory->addGroupAdmin(1, $group->id, 2);

        $this->assertSame($timber->id, $this->resolver->resolve(2, $timber->id)->companyId);
    }

    public function test_staff_cannot_create_groups_or_companies(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->directory->createGroup(3, ['name' => 'Rogue Group']);
    }

    public function test_admin_of_another_group_cannot_add_company_to_this_group(): void
    {
        $group = $this->directory->createGroup(1, ['name' => 'MJ Group']);
        $this->expectException(AuthorizationException::class);
        $this->newCompany(2, $group->id, 'Intruder');
    }

    public function test_picker_lists_groups_and_enters_only_member_companies(): void
    {
        $group = $this->directory->createGroup(1, ['name' => 'MJ Group', 'company_ids' => [$this->company->id]]);
        $this->newCompany(1, $group->id, 'Motumo FMCG');
        $this->actingAs(User::findOrFail(1));

        $this->get('/select-company')->assertOk()->assertSee('MJ Group')->assertSee('Motumo FMCG')->assertDontSee('Company B');
        $this->post('/select-company', ['company_id' => $this->other->id])->assertForbidden();
        $this->post('/select-company', ['company_id' => $this->company->id])->assertRedirect('/dashboard')
            ->assertSessionHas('company_id', $this->company->id)->assertSessionHas('branch_id', $this->branch->id)
            ->assertSessionHas('financial_year_id', $this->year->id);
    }

    public function test_single_company_user_skips_the_picker(): void
    {
        $this->actingAs(User::findOrFail(2));

        $this->get('/select-company')->assertRedirect('/dashboard')->assertSessionHas('company_id', $this->other->id);
    }

    public function test_business_screens_send_ambiguous_sessions_to_the_picker(): void
    {
        $this->other->users()->attach(1);
        DB::table('company_user')->where('user_id', 1)->update(['is_default' => false]);
        $request = Request::create('/dashboard');
        $request->setUserResolver(fn () => User::find(1));
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(10)));

        $response = (new ResolveLegacyCompanyContext($this->resolver))->handle($request, fn () => response('screen'));

        $this->assertTrue($response->isRedirect(route('company.select')));
    }
}
