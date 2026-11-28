<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Multimodal;

use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class CopilotMediaProgressUiContractTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private int $branchId;
    private int $fyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('erp_ai.enabled', true);
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.async.enabled', true);
        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai.providers.nvidia.key', 'nvapi-ui-contract-key');

        $this->company = Company::create([
            'legal_name' => 'UI Contract Co ' . uniqid(),
            'trade_name' => 'UI Contract Co',
            'code' => 'UIC-' . strtoupper(substr(uniqid(), -5)),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
            'timezone' => 'Asia/Kolkata',
        ]);
        $this->branchId = $this->company->branches()->create(['name' => 'Main', 'code' => 'M-' . uniqid(), 'is_active' => true])->id;
        $this->fyId = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ])->id;

        $this->admin = User::create([
            'name' => 'UI Admin',
            'email' => 'ui_' . uniqid() . '@example.com',
            'phone' => '9855500001',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->company->users()->attach($this->admin->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'branch_id' => $this->branchId,
        ]);

        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false, [
            'multimodal_enabled' => true,
            'daily_token_limit' => 200000,
        ]);
    }

    public function test_UI12_workspace_serves_progress_renderer_async_config_and_removes_old_spinner_text(): void
    {
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];
        $res = $this->actingAs($this->admin)->withSession($sess)->get('/ai');

        $res->assertStatus(200);
        $res->assertSee('js/ai-media-progress.js', false);
        $res->assertSee('js/ai-media-input.js', false);
        $res->assertSee('css/pages/ai-workspace.css', false);
        $this->assertMatchesRegularExpression('/asyncEnabled(?:\\\\u0022|"):true/', $res->getContent());
        $res->assertSee('processing-tasks', false);
        $res->assertDontSee('Validating document, resolving records, and automatically preparing ERP proposal with', false);

        $mediaInputJs = file_get_contents(public_path('js/ai-media-input.js'));
        $progressJs = file_get_contents(public_path('js/ai-media-progress.js'));
        $this->assertNotFalse($mediaInputJs);
        $this->assertNotFalse($progressJs);
        $this->assertStringNotContainsString('Validating document, resolving records, and automatically preparing ERP proposal with', $mediaInputJs);
        $this->assertStringNotContainsString('Retrying preparation with', $mediaInputJs);
        $this->assertStringNotContainsString('Retrying extraction with', $mediaInputJs);
        $this->assertStringContainsString('zoloAiMediaProgress', $progressJs);
    }
}
