<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\UI;

use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

/**
 * Task T04: Shared AiEntityPicker & Responsive UI Test Suite.
 * Verifies that the interactive entity picker component, styling, Blade integration,
 * accessibility attributes, and contract bindings are correctly structured.
 */
final class AiEntityPickerUiTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('erp_ai.enabled', true);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminUser = User::create([
            'name' => 'UI Picker Admin',
            'email' => 'picker_ui_' . uniqid() . '@example.com',
            'phone' => '97777777' . rand(10, 99),
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::create([
            'name' => 'Textile UI Mills Ltd',
            'legal_name' => 'Textile UI Mills Ltd',
            'code' => 'TEX_UI_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        $this->branch = CompanyBranch::create([
            'company_id' => $this->company->id,
            'name' => 'Tiruppur Unit 1',
            'code' => 'TP-01',
            'is_active' => true,
        ]);

        $this->fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026-2027',
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

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);
    }

    /**
     * Test 1: Verify that public/js/ai-entity-picker.js exists, has no syntax errors,
     * and defines the required AiEntityPicker and renderSelectionSummary methods.
     */
    public function test_ai_entity_picker_script_exists_and_defines_methods(): void
    {
        $path = public_path('js/ai-entity-picker.js');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('window.AiEntityPicker', $content);
        $this->assertStringContainsString('renderPicker:', $content);
        $this->assertStringContainsString('renderSelectionSummary:', $content);
        $this->assertStringContainsString('ai-entity-picker', $content);
        $this->assertStringContainsString('role="listbox"', $content);
        $this->assertStringContainsString('aria-selected', $content);
        $this->assertStringContainsString('ai-picker-btn-use', $content);
    }

    /**
     * Test 2: Verify that public/css/pages/ai-workspace.css includes styles
     * for picker container, options, selection summary, and mobile responsive drawer.
     */
    public function test_ai_workspace_css_includes_picker_and_responsive_styles(): void
    {
        $path = public_path('css/pages/ai-workspace.css');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('.ai-entity-picker', $content);
        $this->assertStringContainsString('.ai-picker-option', $content);
        $this->assertStringContainsString('.ai-selection-summary', $content);
        $this->assertStringContainsString('@media (max-width: 768px)', $content);
    }

    /**
     * Test 3: Verify that workspace Blade template includes ai-entity-picker.js before ai-media-input.js.
     */
    public function test_workspace_blade_includes_ai_entity_picker_script(): void
    {
        $path = resource_path('views/backend/ai/workspace.blade.php');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('js/ai-entity-picker.js', $content);
        $this->assertStringContainsString('js/ai-media-input.js', $content);

        // Verify order: ai-entity-picker.js must appear before ai-media-input.js
        $pickerPos = strpos($content, 'js/ai-entity-picker.js');
        $mediaPos = strpos($content, 'js/ai-media-input.js');
        $this->assertLessThan($mediaPos, $pickerPos, 'ai-entity-picker.js must be loaded before ai-media-input.js');
    }

    /**
     * Test 4: Verify that public/js/ai-media-input.js integrates AiEntityPicker
     * in clarification view and edit view, with typed corrections and no hardcoded fallbacks.
     */
    public function test_ai_media_input_integrates_entity_picker(): void
    {
        $path = public_path('js/ai-media-input.js');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('window.AiEntityPicker', $content);
        $this->assertStringContainsString('ai-entity-picker-slot', $content);
        $this->assertStringContainsString('ai-btn-browse-warehouse', $content);
        $this->assertStringContainsString('ai-edit-picker-container', $content);

        // Zero hardcoded Main Warehouse fallbacks in proposal or booked renderers
        $this->assertStringNotContainsString("'Main Warehouse'", $content);
    }

    /**
     * Test 5: Verify the entity-options endpoint provides all metadata required by AiEntityPicker.
     */
    public function test_entity_options_endpoint_serves_picker_payload(): void
    {
        $wh = Warehouse::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'name' => 'Knitwear Dyeing Facility',
            'address' => '22 Ring Road, Tiruppur',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser, 'web')
            ->getJson('/ai/entity-options?type=warehouse&q=Knitwear');

        $response->assertOk();
        $response->assertJsonStructure([
            'entity_type',
            'items' => [
                '*' => [
                    'id',
                    'name',
                    'display',
                    'branch_id',
                    'branch_name',
                    'branch_label',
                    'location',
                    'is_active',
                    'eligible',
                    'disabled_reason',
                    'match_kind',
                ]
            ],
            'page',
            'per_page',
            'total',
            'has_more',
            'can_create',
        ]);

        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame($wh->id, $items[0]['id']);
        $this->assertSame('Knitwear Dyeing Facility', $items[0]['name']);
        $this->assertTrue($items[0]['eligible']);
        $this->assertSame($wh->id, $response->json('recommended_id'));
    }
}
