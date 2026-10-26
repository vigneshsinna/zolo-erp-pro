<?php

namespace Tests\Feature;

use App\Services\Documents\DocumentRenderingService;
use App\Services\Platform\CompanyProfileService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, File, Schema};
use Illuminate\Validation\ValidationException;
use Tests\Support\ReturnsDocumentTestCase;

class CompanyProfileTest extends ReturnsDocumentTestCase
{
    private array $logos = [];

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_16_000001_add_profile_to_companies.php'))->up();
        if (!Schema::hasColumn('currencies', 'name')) Schema::table('currencies', fn (Blueprint $t) => $t->string('name')->nullable());
        DB::table('currencies')->update(['name' => 'Indian Rupee']);
    }

    protected function tearDown(): void
    {
        foreach ($this->logos as $logo) File::delete(public_path($logo));
        parent::tearDown();
    }

    private function profile(array $input = [], ?UploadedFile $logo = null)
    {
        $company = app(CompanyProfileService::class)->save($this->company->fresh(), $input + ['legal_name' => 'Motumo Foods Private Limited',
            'trade_name' => 'Motumo FMCG', 'address' => 'No. 4, Mill Road', 'city' => 'Coimbatore', 'state_code' => '33', 'postal_code' => '641001',
            'phone' => '+91 422 400 1000', 'email' => 'accounts@motumo.example', 'gstin' => $this->gstin('33')], $logo, $this->context(), 1);
        if ($company->logo) $this->logos[] = $company->logo;
        return $company;
    }

    public function test_profile_names_address_and_logo_print_on_the_invoice_and_follow_into_gst_registration(): void
    {
        $company = $this->profile([], UploadedFile::fake()->image('logo.png', 120, 60));

        $this->assertSame(substr($this->gstin('33'), 2, 10), $company->pan, 'PAN defaults from the GSTIN.');
        $this->assertFileExists(public_path($company->logo));
        $registration = DB::table('tax_registrations')->where('company_id', $this->company->id)->whereNull('effective_to')->first();
        $this->assertSame(['Motumo Foods Private Limited', 'Motumo FMCG', 'No. 4, Mill Road, Coimbatore, Tamil Nadu 641001'],
            [$registration->legal_name, $registration->trade_name, $registration->address]);
        $this->assertSame(1, DB::table('tax_registrations')->where('company_id', $this->company->id)->count(), 'Same GSTIN updates, never duplicates.');

        $sale = $this->gstSale(); $renderer = app(DocumentRenderingService::class);
        $html = $renderer->html($renderer->dto('sale', $sale->id, $this->context()), $renderer->profile('sale', $this->context(), null, 'a4'));
        foreach (['Motumo FMCG', 'Motumo Foods Private Limited', 'Coimbatore, Tamil Nadu 641001', 'Ph: +91 422 400 1000', 'PAN: '.$company->pan,
            'data:image/png;base64,', 'Rupees Only'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->profile(['trade_name' => 'Motumo Foods'], UploadedFile::fake()->image('new.png', 80, 40));
        $again = $renderer->html($renderer->dto('sale', $sale->id, $this->context()), $renderer->profile('sale', $this->context(), null, 'a4'));
        $this->assertStringContainsString('Motumo FMCG', $again, 'A posted bill keeps the name it was posted with.');
        $this->assertFileExists(public_path($this->logos[0]), 'A new logo never removes the one older bills print.');
    }

    public function test_unregistered_type_registers_without_gstin_from_the_year_start_and_prints_an_invoice(): void
    {
        (require database_path('migrations/2026_10_20_000001_allow_unregistered_company_tax_registration.php'))->up();
        DB::table('tax_registrations')->delete();
        try {
            $this->profile(['registration_type' => 'unregistered']);
            $this->fail('An unregistered business cannot keep a GSTIN.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('gstin', $error->errors());
        }
        $this->profile(['gstin' => '', 'registration_type' => 'unregistered']);
        $registration = DB::table('tax_registrations')->where('company_id', $this->company->id)->sole();
        $this->assertSame([null, '33', 'unregistered', 'Motumo Foods Private Limited'],
            [$registration->gstin, $registration->state_code, $registration->registration_type, $registration->legal_name]);
        $this->assertSame(substr((string) DB::table('fiscal_years')->where('id', $this->year->id)->value('start_date'), 0, 10), substr((string) $registration->effective_from, 0, 10));

        $sale = $this->gstSale(); $renderer = app(DocumentRenderingService::class);
        $html = $renderer->html($renderer->dto('sale', $sale->id, $this->context()), $renderer->profile('sale', $this->context(), null, 'a4'));
        $this->assertStringContainsString('GSTIN: Unregistered', $html);
        $this->assertStringNotContainsString('Tax invoice', $html);
        $this->assertEquals(0, (float) $sale->total_tax);
    }

    public function test_transport_drawer_fields_print_on_the_invoice(): void
    {
        $sale = $this->gstSale(['bale_no' => 'BL-7', 'no_of_bales' => 3, 'bundle_count' => 9, 'vehicle_number' => 'TN39 AB 1234', 'lr_no' => 'LR-55']);
        $renderer = app(DocumentRenderingService::class);
        $html = $renderer->html($renderer->dto('sale', $sale->id, $this->context()), $renderer->profile('sale', $this->context(), null, 'a4'));
        foreach (['Bale No.', 'BL-7', 'Bales', 'Bundles', 'TN39 AB 1234', 'LR-55'] as $text) $this->assertStringContainsString($text, $html);
        $this->expectException(ValidationException::class);
        $this->gstSale(['no_of_bales' => 'many'], 'bad-bales');
    }

    public function test_pan_must_match_gstin_and_a_gstin_needs_an_address(): void
    {
        foreach ([['pan' => 'ZZZZZ9999Z'], ['address' => '']] as $input) {
            try {
                $this->profile($input);
                $this->fail('Profile must be refused: '.json_encode($input));
            } catch (ValidationException $error) {
                $this->assertArrayHasKey(array_key_first($input), $error->errors());
            }
        }
        $this->assertNull($this->company->fresh()->address, 'A refused profile changes nothing.');
    }

    public function test_new_company_dialog_saves_profile_logo_and_gst_registration(): void
    {
        if (!Schema::hasTable('company_setup_audits')) (require database_path('migrations/2026_10_09_000001_create_company_setup_audits.php'))->up();
        if (!Schema::hasTable('company_groups')) (require database_path('migrations/2026_10_13_000001_create_company_groups.php'))->up();
        foreach (['address' => fn (Blueprint $t) => $t->text('address')->nullable(), 'is_active' => fn (Blueprint $t) => $t->boolean('is_active')->nullable()] as $column => $add) {
            if (!Schema::hasColumn('warehouses', $column)) Schema::table('warehouses', $add);
        }
        $gstin = $this->gstin('29');
        $this->post('/company-picker/companies', ['company_group_id' => '', 'trade_name' => 'Weave Mills', 'legal_name' => 'Weave Mills LLP',
            'code' => 'WEAVE', 'industry' => 'textile', 'subtype' => 'manufacturing', 'address' => '7, Mill Street', 'city' => 'Bengaluru',
            'state_code' => '29', 'postal_code' => '560001', 'gstin' => $gstin, 'timezone' => 'Asia/Kolkata', 'fy_start' => '2026-04-01',
            'fy_end' => '2027-03-31', 'logo' => UploadedFile::fake()->image('logo.png', 100, 50)])->assertSessionHasNoErrors()->assertRedirect();

        $company = \App\Models\Company::where('code', 'WEAVE')->firstOrFail(); $this->logos[] = $company->logo;
        $this->assertSame(['Bengaluru', substr($gstin, 2, 10), '29'], [$company->city, $company->pan, $company->state_code]);
        $this->assertFileExists(public_path($company->logo));
        $this->assertSame('Weave Mills LLP', DB::table('tax_registrations')->where('company_id', $company->id)->where('gstin', $gstin)->value('legal_name'));

        // Ready to trade: every posting role has an account (job work loss posts to shrinkage), and the starter masters exist.
        $this->assertSame([], DB::table('semantic_account_mappings')->where('company_id', $company->id)->whereNull('account_id')->pluck('semantic_role')->all());
        $this->assertSame('5020', DB::table('semantic_account_mappings')->where('company_id', $company->id)->where('semantic_role', 'damage')->value('account_code'));
        $this->assertSame(1, DB::table('customer_groups')->where('company_id', $company->id)->count());
        $this->assertSame(['Bank', 'Cash'], DB::table('accounts')->where('company_id', $company->id)->orderBy('name')->pluck('name')->all());
        if (Schema::hasTable('billers')) $this->assertSame(1, DB::table('billers')->where('company_id', $company->id)->count());
        app(\App\Services\Platform\CompanyDirectoryService::class)->installStarterMasters($company);
        $this->assertSame(2, DB::table('accounts')->where('company_id', $company->id)->count(), 'Starter masters are added once.');
    }

    public function test_party_uniqueness_is_per_company_and_ignores_blank_values(): void
    {
        DB::table('suppliers')->update(['email' => null, 'is_active' => 1]);
        $rules = fn () => ['email' => \App\Support\CompanyUnique::rules('suppliers'), 'company_name' => \App\Support\CompanyUnique::rules('suppliers')];
        request()->attributes->set(\App\Services\Platform\CompanyContext::class, $this->context());
        $this->assertTrue(validator(['email' => null, 'company_name' => null], $rules())->passes(), 'Blank email is never a duplicate.');
        $name = DB::table('suppliers')->where('company_id', $this->company->id)->value('company_name') ?? 'Owned supplier';
        DB::table('suppliers')->where('company_id', $this->company->id)->limit(1)->update(['company_name' => $name]);
        $this->assertTrue(validator(['company_name' => $name], $rules())->fails(), 'Same name in the same company is refused.');
        request()->attributes->set(\App\Services\Platform\CompanyContext::class, new \App\Services\Platform\CompanyContext($this->company->id + 1000, $this->branch->id, $this->year->id));
        $this->assertTrue(validator(['company_name' => $name], $rules())->passes(), 'Another company may use the same name.');
    }

    public function test_profile_page_saves_the_company_and_a_logo(): void
    {
        $this->get('/settings/company')->assertOk()->assertSee('Company profile')->assertSee('Base currency');
        $this->post('/settings/company', ['legal_name' => 'Motumo Foods Private Limited', 'trade_name' => 'Motumo FMCG', 'state_code' => '33',
            'city' => 'Coimbatore', 'base_currency_id' => 1, 'logo' => UploadedFile::fake()->image('logo.jpg', 100, 50)])
            ->assertSessionHasNoErrors()->assertRedirect('/settings/company');
        $company = $this->company->fresh(); $this->logos[] = $company->logo;
        $this->assertSame(['Motumo FMCG', 'Coimbatore', 1], [$company->trade_name, $company->city, (int) $company->base_currency_id]);
        $this->assertStringStartsWith(CompanyProfileService::LOGO_DIR.'/company-'.$company->id.'-', $company->logo);
    }

    public function test_features_card_switches_existing_features_off_and_back_on(): void
    {
        // This test case stubs enabled() to true, so start from the real stored state and assert stored rows.
        $this->app->forgetInstance(\App\Services\Platform\CapabilityService::class);
        $this->app->bind(\App\Services\Platform\CapabilityService::class, fn () => new \App\Services\Platform\CapabilityService);
        $stored = fn (string $key) => (bool) DB::table('company_capabilities')->join('capabilities', 'capabilities.id', '=', 'capability_id')
            ->where('company_id', $this->company->id)->where('key', $key)->value('enabled');
        $this->get('/settings/company')->assertOk()->assertSee('Features')->assertSee('POS terminal');
        $keep = array_values(array_diff(\App\Services\Platform\CapabilityCatalog::SWITCHABLE, ['sales.coupons', 'hrm.enabled']));
        $payload = ['legal_name' => 'Motumo Foods Private Limited', 'state_code' => '33', 'base_currency_id' => 1, 'features_submitted' => 1];
        $this->post('/settings/company', $payload + ['features' => $keep])->assertSessionHasNoErrors();
        $this->assertFalse($stored('sales.coupons'));
        $this->assertFalse($stored('hrm.enabled'));
        \Illuminate\Support\Facades\Cache::flush();
        $this->post('/settings/company', $payload + ['features' => \App\Services\Platform\CapabilityCatalog::SWITCHABLE])->assertSessionHasNoErrors();
        $this->assertTrue($stored('sales.coupons'));
        $this->assertTrue($stored('hrm.enabled'));
    }
}
