<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use Tests\TestCase;

class DashboardSmokeTest extends TestCase
{
    public function test_dashboard_renders_content(): void
    {
        $user = User::first();
        $company = Company::first();

        $response = $this->actingAs($user)
            ->withSession([
                'company_id' => $company->id,
                'branch_id' => $company->branches()->first()->id ?? 1,
            ])
            ->get('/dashboard');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('dashboard-counts', $content);
    }
}
