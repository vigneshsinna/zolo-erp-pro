<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CommandCenterScriptOrderingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sales_page_renders_window_command_center_grid_before_dependent_scripts(): void
    {
        $user = User::first();
        $company = Company::find(6) ?? Company::first();
        $branch = $company->branches()->first();
        $fy = $company->fiscalYears()->first();

        $response = $this->actingAs($user)
            ->withSession([
                'company_id' => $company->id,
                'branch_id' => $branch ? $branch->id : 1,
                'financial_year_id' => $fy ? $fy->id : 1,
            ])
            ->get('/sales');

        $response->assertStatus(200);
        $html = $response->getContent();

        $gridPos = strpos($html, 'window.commandCenterGrid');
        $formScriptPos = strpos($html, 'command-center-form.js');
        $workspaceScriptPos = strpos($html, 'document-workspace.js');

        $this->assertNotFalse($gridPos, 'window.commandCenterGrid must be defined in sales HTML');
        $this->assertNotFalse($formScriptPos, 'command-center-form.js must be present in sales HTML');
        $this->assertNotFalse($workspaceScriptPos, 'document-workspace.js must be present in sales HTML');

        $this->assertLessThan(
            $formScriptPos,
            $gridPos,
            'window.commandCenterGrid must be initialized before command-center-form.js executes'
        );

        $this->assertLessThan(
            $workspaceScriptPos,
            $gridPos,
            'window.commandCenterGrid must be initialized before document-workspace.js executes'
        );
    }

    public function test_purchase_page_renders_window_command_center_grid_before_dependent_scripts(): void
    {
        $user = User::first();
        $company = Company::find(6) ?? Company::first();
        $branch = $company->branches()->first();
        $fy = $company->fiscalYears()->first();

        $response = $this->actingAs($user)
            ->withSession([
                'company_id' => $company->id,
                'branch_id' => $branch ? $branch->id : 1,
                'financial_year_id' => $fy ? $fy->id : 1,
            ])
            ->get('/purchases');

        $response->assertStatus(200);
        $html = $response->getContent();

        $gridPos = strpos($html, 'window.commandCenterGrid');
        $formScriptPos = strpos($html, 'command-center-form.js');
        $workspaceScriptPos = strpos($html, 'document-workspace.js');

        $this->assertNotFalse($gridPos, 'window.commandCenterGrid must be defined in purchase HTML');
        $this->assertNotFalse($formScriptPos, 'command-center-form.js must be present in purchase HTML');
        $this->assertNotFalse($workspaceScriptPos, 'document-workspace.js must be present in purchase HTML');

        $this->assertLessThan(
            $formScriptPos,
            $gridPos,
            'window.commandCenterGrid must be initialized before command-center-form.js executes'
        );

        $this->assertLessThan(
            $workspaceScriptPos,
            $gridPos,
            'window.commandCenterGrid must be initialized before document-workspace.js executes'
        );
    }
}

