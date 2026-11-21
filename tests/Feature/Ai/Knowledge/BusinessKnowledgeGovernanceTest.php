<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Knowledge;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Knowledge\BusinessKnowledgeRegistry;
use App\Ai\Knowledge\BusinessWorkflowPlanner;
use App\Ai\Knowledge\KnowledgeDefinitionValidator;
use App\Ai\Knowledge\ScopedBusinessFactReader;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class BusinessKnowledgeGovernanceTest extends TestCase
{
    use UsesAiMysql;

    private User $readOnlyUser;
    private User $unauthorizedUser;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;
    private Supplier $supplier;
    private Warehouse $warehouse;
    private Product $product;
    private Unit $unit;
    private CompanyContext $context;
    private AiPrincipal $readOnlyPrincipal;
    private AiPrincipal $unauthorizedPrincipal;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_business_knowledge.enabled', true);
        config()->set('ai_business_knowledge.planner_enabled', true);
        config()->set('ai_business_knowledge.workflow_enabled', true);
        config()->set('ai_actions.foundation_enabled', true);
        config()->set('commercial.enabled', true);
        config()->set('compliance.enabled', true);
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);
        DB::table('roles')->updateOrInsert(['id' => 5], ['name' => 'Viewer', 'is_active' => true, 'guard_name' => 'web']);
        DB::table('roles')->updateOrInsert(['id' => 9], ['name' => 'Restricted', 'is_active' => true, 'guard_name' => 'web']);

        $this->readOnlyUser = User::create([
            'name' => 'Read Only Auditor',
            'email' => 'auditor_'.Str::random(8).'@example.test',
            'phone' => '97777'.rand(10000, 99999),
            'password' => bcrypt('secret'),
            'role_id' => 5,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->unauthorizedUser = User::create([
            'name' => 'Unauthorized Clerk',
            'email' => 'unauth_'.Str::random(8).'@example.test',
            'phone' => '96666'.rand(10000, 99999),
            'password' => bcrypt('secret'),
            'role_id' => 9,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::create([
            'legal_name' => 'Governance Mills Ltd',
            'code' => 'GML_'.Str::random(8),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->branch = $this->company->branches()->create([
            'code' => 'GB1',
            'name' => 'Governance Branch 1',
            'branch_type' => 'branch',
            'is_active' => true,
        ]);

        $this->fiscalYear = $this->company->fiscalYears()->create([
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_closed' => false,
        ]);

        $this->company->users()->syncWithoutDetaching([
            $this->readOnlyUser->id => ['is_default' => true],
            $this->unauthorizedUser->id => ['is_default' => true],
        ]);

        DB::table('company_user_branches')->insert([
            ['company_id' => $this->company->id, 'user_id' => $this->readOnlyUser->id, 'branch_id' => $this->branch->id],
            ['company_id' => $this->company->id, 'user_id' => $this->unauthorizedUser->id, 'branch_id' => $this->branch->id],
        ]);

        // Grant read-only permissions to Role 5 (Viewer)
        $permSupplier = DB::table('permissions')->updateOrInsert(['name' => 'suppliers-index', 'guard_name' => 'web'], ['created_at' => now()]);
        $supplierPermId = DB::table('permissions')->where('name', 'suppliers-index')->value('id');

        $permPurchase = DB::table('permissions')->updateOrInsert(['name' => 'purchases-index', 'guard_name' => 'web'], ['created_at' => now()]);
        $purchasePermId = DB::table('permissions')->where('name', 'purchases-index')->value('id');

        $permProduct = DB::table('permissions')->updateOrInsert(['name' => 'products-index', 'guard_name' => 'web'], ['created_at' => now()]);
        $productPermId = DB::table('permissions')->where('name', 'products-index')->value('id');

        $permWarehouse = DB::table('permissions')->updateOrInsert(['name' => 'warehouse', 'guard_name' => 'web'], ['created_at' => now()]);
        $warehousePermId = DB::table('permissions')->where('name', 'warehouse')->value('id');

        $permAiUse = DB::table('permissions')->updateOrInsert(['name' => 'ai.use', 'guard_name' => 'web'], ['created_at' => now()]);
        $aiUsePermId = DB::table('permissions')->where('name', 'ai.use')->value('id');

        DB::table('role_has_permissions')->insertOrIgnore([
            ['role_id' => 5, 'permission_id' => $supplierPermId],
            ['role_id' => 5, 'permission_id' => $purchasePermId],
            ['role_id' => 5, 'permission_id' => $productPermId],
            ['role_id' => 5, 'permission_id' => $warehousePermId],
            ['role_id' => 5, 'permission_id' => $aiUsePermId],
            ['role_id' => 9, 'permission_id' => $aiUsePermId], // role 9 has ONLY ai.use, NO index permissions
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Verified Raw Yarn Corp',
            'company_name' => 'Raw Yarn Corp',
            'vat_number' => '33AABCT9999A1Z1',
            'phone_number' => '9842199999',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Main Godown',
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'address' => 'Station Road',
            'is_active' => true,
        ]);

        $this->unit = Unit::create([
            'unit_code' => 'KGS',
            'unit_name' => 'Kilograms',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->product = Product::forceCreate([
            'name' => 'Cotton Combed Yarn 40s',
            'code' => 'YRN-40S-'.Str::random(4),
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => 1,
            'unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'cost' => 240.00,
            'price' => 310.00,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->context = new CompanyContext($this->company->id, $this->branch->id, $this->fiscalYear->id);
        $this->readOnlyPrincipal = new AiPrincipal(
            actorId: $this->readOnlyUser->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $this->fiscalYear->id,
            effectiveRole: 'viewer',
        );
        $this->unauthorizedPrincipal = new AiPrincipal(
            actorId: $this->unauthorizedUser->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $this->fiscalYear->id,
            effectiveRole: 'restricted',
        );
    }

    public function test_unreviewed_signoff_cannot_be_forged_by_changing_status(): void
    {
        [$doc, $manifest] = $this->loadFiles();
        // Merely setting status to reviewed without signoff fields must throw
        $doc['definitions'][0]['status'] = 'reviewed';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reviewed definition entity.supplier.v1 needs signoff.');
        (new KnowledgeDefinitionValidator)->validate($doc, $manifest, base_path());
    }

    public function test_reviewed_status_requires_complete_governance_metadata(): void
    {
        [$doc, $manifest] = $this->loadFiles();
        $def = &$doc['definitions'][0];
        $def['status'] = 'reviewed';
        $def['reviewed_at'] = '2026-11-21T05:00:00Z';
        $def['reviewed_by'] = 'purchasing-lead@zoloerp.pro';
        // Missing domain_policy_ref
        try {
            (new KnowledgeDefinitionValidator)->validate($doc, $manifest, base_path());
            $this->fail('Expected InvalidArgumentException for missing domain_policy_ref.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('requires domain_policy_ref', $e->getMessage());
        }

        $def['domain_policy_ref'] = 'policy.supplier_active.v1';
        // Missing signoff_evidence
        try {
            (new KnowledgeDefinitionValidator)->validate($doc, $manifest, base_path());
            $this->fail('Expected InvalidArgumentException for missing signoff_evidence.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('requires signoff_evidence', $e->getMessage());
        }

        $def['signoff_evidence'] = ['signoff_id' => 'SIG-001'];
        // Missing impacted_workflows
        try {
            (new KnowledgeDefinitionValidator)->validate($doc, $manifest, base_path());
            $this->fail('Expected InvalidArgumentException for missing impacted_workflows.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('requires impacted_workflows', $e->getMessage());
        }

        $def['impacted_workflows'] = ['process.purchase_to_pay.v1'];
        // Missing rollback_policy
        try {
            (new KnowledgeDefinitionValidator)->validate($doc, $manifest, base_path());
            $this->fail('Expected InvalidArgumentException for missing rollback_policy.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('requires rollback_policy', $e->getMessage());
        }

        $def['rollback_policy'] = 'Revert to draft; fallback to manual voucher.';
        // Now it succeeds
        (new KnowledgeDefinitionValidator)->validate($doc, $manifest, base_path());
        $this->assertTrue(true);
    }

    public function test_unsupported_processes_cannot_be_marked_reviewed(): void
    {
        [$doc, $manifest] = $this->loadFiles();
        foreach ($doc['definitions'] as &$row) {
            if ($row['id'] === 'process.expense_payable.v1') {
                $row['status'] = 'reviewed';
                $row['reviewed_at'] = '2026-11-21T05:00:00Z';
                $row['reviewed_by'] = 'lead@zoloerp.pro';
                $row['domain_policy_ref'] = 'policy.supplier_active.v1';
                $row['signoff_evidence'] = ['signoff_id' => 'SIG-999'];
                $row['impacted_workflows'] = [];
                $row['rollback_policy'] = 'None';
                break;
            }
        }
        unset($row);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported process process.expense_payable.v1 cannot be marked reviewed.');
        (new KnowledgeDefinitionValidator)->validate($doc, $manifest, base_path());
    }

    public function test_reviewed_process_cannot_reference_unreviewed_action(): void
    {
        [$doc, $manifest] = $this->loadFiles();
        foreach ($doc['definitions'] as &$row) {
            if ($row['id'] === 'process.purchase_to_pay.v1') {
                $row['status'] = 'reviewed';
                $row['reviewed_at'] = '2026-11-21T05:00:00Z';
                $row['reviewed_by'] = 'lead@zoloerp.pro';
                $row['domain_policy_ref'] = 'policy.supplier_active.v1';
                $row['signoff_evidence'] = ['signoff_id' => 'SIG-P2P'];
                $row['impacted_workflows'] = ['process.purchase_to_pay.v1'];
                $row['rollback_policy'] = 'None';
                break;
            }
        }
        unset($row);

        // process is marked reviewed, but its step actions are still 'draft'
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('references unreviewed action');
        (new KnowledgeDefinitionValidator)->validate($doc, $manifest, base_path());
    }

    public function test_draft_or_retired_process_cannot_start_executable_workflow(): void
    {
        $planner = app(BusinessWorkflowPlanner::class);

        $preview = $planner->preview($this->readOnlyPrincipal, 'process.purchase_to_pay.v1');
        $this->assertSame('draft', $preview['definition_status']);
        $this->assertFalse($preview['can_start']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Process is unreviewed or has unsupported steps.');
        $planner->start($this->readOnlyPrincipal, 'process.purchase_to_pay.v1');
    }

    public function test_scoped_fact_reader_allows_read_only_permissions(): void
    {
        $factReader = app(ScopedBusinessFactReader::class);

        // Viewer role has suppliers-index (NO suppliers-add)
        $fact = $factReader->find($this->readOnlyPrincipal, 'supplier', $this->supplier->id);
        $this->assertSame('tenant_fact', $fact['source']);
        $this->assertSame('supplier', $fact['kind']);
        $this->assertSame($this->supplier->id, $fact['facts']['id']);
        $this->assertSame('Verified Raw Yarn Corp', $fact['facts']['name']);
        $this->assertFalse($fact['can_create']);
        $this->assertFalse($fact['writes_executed']);

        // Viewer role has products-index (NO products-add)
        $productFact = $factReader->find($this->readOnlyPrincipal, 'product', $this->product->id);
        $this->assertSame($this->product->id, $productFact['facts']['id']);
        $this->assertSame('standard', $productFact['facts']['type']);
        $this->assertFalse($productFact['can_create']);
    }

    public function test_scoped_fact_reader_denies_user_without_read_permission(): void
    {
        $factReader = app(ScopedBusinessFactReader::class);

        // Restricted user has NO suppliers-index or suppliers-add
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Read permission required for fact kind supplier');
        $factReader->find($this->unauthorizedPrincipal, 'supplier', $this->supplier->id);
    }

    public function test_fact_reader_strictly_redacts_sensitive_fields_and_isolates_foreign_tenant(): void
    {
        $factReader = app(ScopedBusinessFactReader::class);

        $fact = $factReader->find($this->readOnlyPrincipal, 'product', $this->product->id);
        // Sensitive fields like cost, wholesale price, profit margin, deleted_at must NEVER be in projection
        $this->assertArrayNotHasKey('cost', $fact['facts']);
        $this->assertArrayNotHasKey('wholesale_price', $fact['facts']);
        $this->assertArrayNotHasKey('profit_margin', $fact['facts']);

        // Foreign company isolation
        $otherCompany = Company::create([
            'legal_name' => 'Foreign Corp',
            'code' => 'FC_'.Str::random(8),
            'status' => 'active',
        ]);
        $otherSupplier = Supplier::create([
            'name' => 'Foreign Supplier',
            'company_name' => 'Foreign Corp',
            'phone_number' => '9842100099',
            'company_id' => $otherCompany->id,
            'is_active' => true,
        ]);

        $this->expectException(ModelNotFoundException::class);
        $factReader->find($this->readOnlyPrincipal, 'supplier', $otherSupplier->id);
    }

    public function test_codeowners_file_enforces_required_reviewers(): void
    {
        $path = base_path('.github/CODEOWNERS');
        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);
        $this->assertStringContainsString('/resources/ai/knowledge/', $content);
        $this->assertStringContainsString('purchasing-reviewers', $content);
        $this->assertStringContainsString('finance-reviewers', $content);
        $this->assertStringContainsString('security-reviewers', $content);
    }

    private function loadFiles(): array
    {
        $dir = base_path('resources/ai/knowledge/');
        return [
            json_decode((string) file_get_contents($dir.'definitions.json'), true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) file_get_contents($dir.'source_manifest.json'), true, flags: JSON_THROW_ON_ERROR),
        ];
    }
}
