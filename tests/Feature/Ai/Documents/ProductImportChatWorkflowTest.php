<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Documents;

use App\Ai\Documents\DocumentIntakeService;
use App\Ai\Documents\VirusScanner;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Imports\DocumentActionCatalog;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\ApprovedActionExecutor;
use App\Ai\Tools\PrepareProductImport;
use App\Ai\Tools\SimulateProductImport;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class ProductImportChatWorkflowTest extends TestCase
{
    use UsesAiMysql;

    private AiPrincipal $principal;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai_documents.enabled' => true,
            'erp_ai.enabled' => true,
            'erp_ai.read_only' => false,
            'ai_actions.foundation_enabled' => true,
            'ai_actions.execution_enabled' => true,
            'commercial.enabled' => true,
        ]);
        Storage::fake('local');

        $user = User::forceCreate([
            'name' => 'Import Workflow Admin',
            'email' => uniqid('import_admin_') . '@example.test',
            'phone' => '9000000094',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $company = Company::forceCreate([
            'legal_name' => 'Import Workflow Co',
            'code' => uniqid('IMP'),
            'status' => 'active',
            'country_code' => 'IN',
        ]);
        $branch = CompanyBranch::forceCreate([
            'company_id' => $company->id,
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'branch_type' => 'branch',
            'is_active' => true,
        ]);
        $year = FiscalYear::forceCreate([
            'company_id' => $company->id,
            'name' => '2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_closed' => false,
        ]);

        $company->users()->attach($user->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'branch_id' => $branch->id,
        ]);

        $this->actingAs($user);
        app(AiFeatureGate::class)->setCompanySetting($company->id, true, false);

        $registry = app(ActionCapabilityRegistry::class);
        foreach (DocumentActionCatalog::definitions() as $definition) {
            if (!$registry->has($definition->key)) {
                $registry->register($definition);
            }
        }

        $keys = array_keys($registry->all());
        $this->principal = new AiPrincipal(
            actorId: $user->id,
            companyId: $company->id,
            branchId: $branch->id,
            financialYearId: $year->id,
            permittedToolNames: $keys,
        );

        $this->categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'Chat Import Category',
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $this->mock(VirusScanner::class)->shouldReceive('assertClean')->andReturnNull();
    }

    public function test_simulate_product_import_and_prepare_proposal_executes_cleanly(): void
    {
        $csvContent = "name,code,type,category_id,cost,price\n"
            . "Chat Item Alpha,AI-CHAT-ALPHA,service,{$this->categoryId},10,20\n"
            . "Chat Item Beta,AI-CHAT-BETA,service,{$this->categoryId},15,30\n";

        $intake = app(DocumentIntakeService::class);
        $doc = $intake->upload(
            $this->principal,
            UploadedFile::fake()->createWithContent('chat_import.csv', $csvContent),
            'product_import'
        );
        $intake->process($this->principal, $doc['id']);

        // Step 1: Simulate product import dry-run via SDK Tool
        $simulateTool = new SimulateProductImport($this->principal);
        $simResponseJson = $simulateTool->handle(new ToolRequest(['document_id' => $doc['id']]));
        $simResponse = json_decode($simResponseJson, true);

        $this->assertSame('ok', $simResponse['status']);
        $this->assertNotEmpty($simResponse['batch_id']);
        $this->assertSame(2, $simResponse['counts']['valid']);
        $this->assertSame(0, $simResponse['counts']['rejected']);

        $batchId = $simResponse['batch_id'];
        $reviewHash = $simResponse['review_hash'];

        // Step 2: Prepare action proposal via SDK Tool
        $prepareTool = new PrepareProductImport($this->principal);
        $prepResponseJson = $prepareTool->handle(new ToolRequest([
            'batch_id' => $batchId,
            'review_hash' => $reviewHash,
        ]));
        $prepResponse = json_decode($prepResponseJson, true);

        $this->assertSame('ok', $prepResponse['status']);
        $this->assertSame('setup.products.import.v1', $prepResponse['tool']);
        $proposalId = $prepResponse['data']['proposal_id'];

        // Step 3: Approve and Execute the proposal
        $beforeProductCount = Product::where('company_id', $this->principal->companyId)->count();

        app(ActionApprovalService::class)->approve($this->principal, $proposalId, 1);
        $execution = app(ApprovedActionExecutor::class)->execute($this->principal, $proposalId, 1);

        $this->assertSame('completed', $execution['receipt']['status']);
        $this->assertSame('product_import', $execution['receipt']['resource_type']);

        $afterProductCount = Product::where('company_id', $this->principal->companyId)->count();
        $this->assertSame($beforeProductCount + 2, $afterProductCount);

        $this->assertDatabaseHas('products', [
            'company_id' => $this->principal->companyId,
            'code' => 'AI-CHAT-ALPHA',
        ]);
        $this->assertDatabaseHas('products', [
            'company_id' => $this->principal->companyId,
            'code' => 'AI-CHAT-BETA',
        ]);
    }
}

