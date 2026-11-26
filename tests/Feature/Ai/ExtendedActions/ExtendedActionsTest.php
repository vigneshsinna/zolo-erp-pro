<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\ExtendedActions;

use App\Ai\Adapters\ExtendedActionAdapter;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Registries\ExtendedActionCatalog;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\ApprovedActionExecutor;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\SemanticAccountMapping;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\StockLine;
use App\Services\Inventory\StockMovementCommand;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class ExtendedActionsTest extends TestCase
{
    use UsesAiMysql;

    private User $actor;
    private CompanyContext $context;
    private AiPrincipal $principal;
    private Product $product;
    private Warehouse $source;
    private Warehouse $destination;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp_ai.enabled' => true, 'erp_ai.read_only' => false,
            'ai_actions.foundation_enabled' => true, 'ai_actions.execution_enabled' => true]);
        $company = Company::where('status', 'active')->where('code', 'ZTEX')->firstOrFail();
        $branch = $company->branches()->firstOrFail();
        $year = $company->fiscalYears()->where('status', 'open')->firstOrFail();
        $this->actor = User::forceCreate(['name' => 'Extended Action Administrator', 'email' => uniqid('extended_').'@example.test',
            'phone' => '9000000098', 'password' => bcrypt('secret'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false]);
        $company->users()->attach($this->actor->id);
        DB::table('company_user_branches')->insert(['company_id' => $company->id, 'user_id' => $this->actor->id, 'branch_id' => $branch->id]);
        $this->actingAs($this->actor);
        $this->context = new CompanyContext($company->id, $branch->id, $year->id);
        $this->principal = new AiPrincipal(actorId: $this->actor->id, companyId: $company->id, branchId: $branch->id, financialYearId: $year->id);
        app(AiFeatureGate::class)->setCompanySetting($company->id, true, false);
        ExtendedActionCatalog::register(app(ActionCapabilityRegistry::class));
        $this->source = Warehouse::where('company_id', $company->id)->where('branch_id', $branch->id)->firstOrFail();
        $this->destination = Warehouse::forceCreate(['company_id' => $company->id, 'branch_id' => $branch->id,
            'name' => 'AI Transfer Destination', 'address' => 'Test', 'is_active' => true]);
        $unit = Unit::where('company_id', $company->id)->first();
        if (!$unit) {
            $unit = Unit::forceCreate([
                'company_id' => $company->id,
                'unit_code' => 'MTR',
                'unit_name' => 'Meter',
                'is_active' => true,
            ]);
        }
        $prototype = Product::where('company_id', $company->id)->where('type', 'standard')->firstOrFail();
        $this->product = $prototype->replicate(['code', 'name']);
        $this->product->forceFill(['code' => uniqid('EXT-'), 'name' => 'AI transfer stock', 'qty' => 0, 'cost' => 5, 'price' => 10,
            'unit_id' => $unit->id, 'is_batch' => false, 'is_imei' => false, 'is_variant' => false])->save();
    }

    public function test_transfer_preview_is_read_only_and_approval_posts_balanced_movement_once(): void
    {
        $this->opening(20);
        $before = DB::table('stock_movements')->where('company_id', $this->context->companyId)->count();
        $proposal = app(ActionProposalService::class)->create($this->principal, 'stock_transfer.create.v1', $this->transfer(7));
        $this->assertSame($before, DB::table('stock_movements')->where('company_id', $this->context->companyId)->count());
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        $result = app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
        $this->assertSame('completed', $result['status']);
        $id = $result['receipt']['resource_id'];
        $lines = DB::table('stock_movement_lines')->where('company_id', $this->context->companyId)->where('stock_movement_id', $id);
        $this->assertEquals(0, (clone $lines)->sum('qty_base'));
        $this->assertEquals(0, (clone $lines)->sum('value'));
        $this->assertEquals(13, DB::table('product_warehouse')->where('company_id', $this->context->companyId)
            ->where('product_id', $this->product->id)->where('warehouse_id', $this->source->id)->sum('qty'));
        $repeat = app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
        $this->assertSame($id, $repeat['receipt']['resource_id']);
    }

    public function test_transfer_rejects_foreign_warehouse_and_insufficient_stock(): void
    {
        $this->opening(2);
        $this->rejectPreview('stock_transfer.create.v1', $this->transfer(3), 'exceeds available');
        $foreign = Company::forceCreate(['code' => uniqid('FOREIGN-'), 'legal_name' => 'Foreign Company',
            'country_code' => 'IN', 'timezone' => 'Asia/Kolkata', 'status' => 'active']);
        $warehouse = Warehouse::where('company_id', $foreign->id)->first();
        if (!$warehouse) {
            $warehouse = Warehouse::forceCreate(['company_id' => $foreign->id, 'name' => 'Foreign', 'address' => 'Foreign', 'is_active' => true]);
        }
        $data = $this->transfer(1);
        $data['to_warehouse_id'] = $warehouse->id;
        $this->rejectPreview('stock_transfer.create.v1', $data, 'selected company and branch');
    }

    public function test_source_change_invalidates_approved_transfer_without_stock_mutation(): void
    {
        $this->opening(20);
        $proposal = app(ActionProposalService::class)->create($this->principal, 'stock_transfer.create.v1', $this->transfer(5));
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        $this->product->forceFill(['price' => 12])->save();
        $before = DB::table('stock_movements')->where('company_id', $this->context->companyId)->count();
        try {
            app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
            $this->fail('Changed source must invalidate approval.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('source state changed', $error->getMessage());
        }
        $this->assertSame($before, DB::table('stock_movements')->where('company_id', $this->context->companyId)->count());
    }

    public function test_journal_rejects_unbalanced_values_and_posts_exact_balanced_values_once(): void
    {
        $debit = $this->account('AI debit', 'expense');
        $credit = $this->account('AI credit', 'liability');
        $data = ['business_date' => '2026-11-12', 'description' => 'Approved accrual journal', 'items' => [
            ['chart_of_account_id' => $debit->id, 'debit' => '12.3456'],
            ['chart_of_account_id' => $credit->id, 'credit' => '12.3455'],
        ]];
        $this->rejectPreview('journal_entry.create.v1', $data, 'balance exactly');
        $data['items'][1]['credit'] = '12.3456';
        $proposal = app(ActionProposalService::class)->create($this->principal, 'journal_entry.create.v1', $data);
        $this->withHeaders(['X-Company-Id' => $this->context->companyId, 'X-Branch-Id' => $this->context->branchId,
            'X-Financial-Year-Id' => $this->context->financialYearId])->get('/ai/action-review')
            ->assertOk()->assertSee('Approved accrual journal')->assertSee('12.3456')
            ->assertSee('Approve & Execute', false)->assertSee('Review all action details')
            ->assertDontSee('Order placement is non-posting');
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        $result = app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
        $entry = DB::table('journal_entries')->where('company_id', $this->context->companyId)->where('id', $result['receipt']['resource_id'])->first();
        $this->assertSame('12.3456', $entry->total_debit);
        $this->assertSame('12.3456', $entry->total_credit);
        $this->assertSame('posted', $entry->status);
    }

    public function test_account_creation_requires_approval_and_preserves_zero_balances(): void
    {
        $data = ['code' => 'AI-FREIGHT', 'name' => 'AI Freight Expense', 'type' => 'expense', 'sub_type' => 'operating_expense'];
        $proposal = app(ActionProposalService::class)->create($this->principal, 'setup.account.create.v1', $data);
        $this->assertFalse(ChartOfAccount::where('company_id', $this->context->companyId)->where('code', 'AI-FREIGHT')->exists());
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        $result = app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
        $account = ChartOfAccount::where('company_id', $this->context->companyId)->findOrFail($result['receipt']['resource_id']);
        $this->assertSame('0.0000', $account->current_balance);
        $this->assertSame('0.0000', $account->opening_balance);
        $this->rejectPreview('setup.account.create.v1', $data, 'already uses');
    }

    public function test_price_list_shows_old_new_diff_and_updates_only_after_approval(): void
    {
        $data = ['effective_date' => now('Asia/Kolkata')->toDateString(), 'reason' => 'Reviewed customer price revision',
            'items' => [['product_id' => $this->product->id, 'price' => '10.5000']]];
        $proposal = app(ActionProposalService::class)->create($this->principal, 'setup.pricelist.update.v1', $data);
        $preview = json_decode($proposal['preview_json'], true);
        $this->assertSame('10.0000', $preview['changes'][0]['old_price']);
        $this->assertSame('10.5000', $preview['changes'][0]['new_price']);
        $this->assertEquals(10, $this->product->fresh()->price);
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
        $this->assertEquals(10.5, $this->product->fresh()->price);
    }

    public function test_stock_adjustment_posts_movement_value_and_balanced_variance_journal(): void
    {
        $this->opening(20);
        $data = ['business_date' => '2026-11-12', 'warehouse_id' => $this->source->id, 'reason' => 'Counted and reviewed damage',
            'lines' => [['product_id' => $this->product->id, 'qty' => '-2.0000', 'uom_id' => $this->product->unit_id]]];
        $proposal = app(ActionProposalService::class)->create($this->principal, 'stock_adjust.create.v1', $data);
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        $result = app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
        $entry = DB::table('journal_entries')->where('company_id', $this->context->companyId)->where('reference_type', 'adjustment')
            ->where('reference_id', $result['receipt']['resource_id'])->first();
        $this->assertNotNull($entry);
        $this->assertSame('10.0000', $entry->total_debit);
        $this->assertSame('10.0000', $entry->total_credit);
        $this->assertEquals(18, $this->product->fresh()->qty);
    }

    public function test_opening_proposals_reject_unvalidated_batches(): void
    {
        $this->rejectPreview('setup.opening_stock.create.v1', ['batch_id' => 99999999], 'already validated');
        $this->rejectPreview('setup.opening_balance.create.v1', ['batch_id' => 99999999], 'already validated');
    }

    public function test_production_plan_uses_published_bom_without_consuming_stock(): void
    {
        $this->enableOperations();
        $output = $this->product->replicate(['code']);
        $output->code = uniqid('OUTPUT-');
        $output->save();
        $bom = app(\App\Services\Manufacturing\BomService::class)->create([
            'code' => 'AI-BOM', 'product_id' => $output->id, 'effective_from' => '2026-11-01',
            'output_qty' => '1', 'output_uom_id' => $output->unit_id,
            'lines' => [['component_product_id' => $this->product->id, 'qty' => '2', 'uom_id' => $this->product->unit_id]],
        ], uniqid('bom-'), $this->context, $this->actor->id);
        $proposal = app(ActionProposalService::class)->create($this->principal, 'production_order.create.v1', [
            'bom_id' => $bom->id, 'warehouse_id' => $this->source->id, 'business_date' => '2026-11-12', 'planned_qty' => '5',
        ]);
        $preview = json_decode($proposal['preview_json'], true);
        $this->assertSame('10.0000', $preview['components'][0]['planned_qty']);
        $this->assertNotEmpty($preview['warnings']);
        $before = DB::table('stock_movements')->where('company_id', $this->context->companyId)->count();
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        $result = app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
        $order = DB::table('production_orders')->where('company_id', $this->context->companyId)->where('id', $result['receipt']['resource_id'])->first();
        $this->assertSame('planned', $order->status);
        $this->assertSame($before, DB::table('stock_movements')->where('company_id', $this->context->companyId)->count());
        $this->opening(20);
        $completion = app(ActionProposalService::class)->create($this->principal, 'production_order.complete.v1', [
            'business_date' => '2026-11-12', 'production_order_id' => $order->id, 'completed_qty' => '5.0000',
        ]);
        app(ActionApprovalService::class)->approve($this->principal, $completion['id'], 1);
        $completed = app(ApprovedActionExecutor::class)->execute($this->principal, $completion['id'], 1);
        $this->assertSame('completed', $completed['receipt']['details']['status']);
        $this->assertEquals(10, $this->product->fresh()->qty);
        $this->assertEquals(5, $output->fresh()->qty);
    }

    public function test_job_work_dispatch_and_partial_receipt_use_actual_domain_commands(): void
    {
        $this->enableOperations();
        $this->opening(20);
        $service = app(\App\Services\JobWork\JobWorkService::class);
        $process = $service->configureProcess(['code' => 'AI-PROCESS', 'name' => 'AI process', 'max_loss_percent' => 4,
            'conversion' => false], $this->context, $this->actor->id);
        $supplier = \App\Models\Supplier::where('company_id', $this->context->companyId)->where('is_active', true)->firstOrFail();
        $order = $service->order(['job_worker_party_id' => $supplier->id, 'process_type_id' => $process,
            'business_date' => '2026-11-12'], uniqid('job-order-'), $this->context, $this->actor->id);
        $proposal = app(ActionProposalService::class)->create($this->principal, 'job_work.outward.v1', [
            'business_date' => '2026-11-12', 'warehouse_id' => $this->source->id, 'order_id' => $order->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'uom_id' => $this->product->unit_id]],
        ]);
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        $result = app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
        $dispatchId = $result['receipt']['resource_id'];
        $line = DB::table('job_work_dispatch_lines')->where('company_id', $this->context->companyId)->where('dispatch_id', $dispatchId)->first();
        $receipt = app(ActionProposalService::class)->create($this->principal, 'job_work.inward.v1', [
            'business_date' => '2026-11-12', 'warehouse_id' => $this->source->id, 'dispatch_id' => $dispatchId,
            'lines' => [['dispatch_line_id' => $line->id, 'accepted_qty' => '6', 'rejected_qty' => '0', 'loss_qty' => '0']],
        ]);
        app(ActionApprovalService::class)->approve($this->principal, $receipt['id'], 1);
        $received = app(ApprovedActionExecutor::class)->execute($this->principal, $receipt['id'], 1);
        $this->assertSame('job_work_receipt', $received['receipt']['resource_type']);
        $this->assertEquals(16, DB::table('product_warehouse')->where('company_id', $this->context->companyId)
            ->where('warehouse_id', $this->source->id)->where('product_id', $this->product->id)->sum('qty'));
        $pending = $service->pending($this->context, $this->actor->id);
        $this->assertEquals(4, collect($pending)->firstWhere('order_id', $order->id)['pending_qty']);
    }

    public function test_validated_opening_batch_commits_stock_and_financial_entries_once(): void
    {
        [$context, $principal, $warehouse, $product, $offset] = $this->cleanOpeningTarget();
        $opening = app(\App\Services\Deployment\OpeningImportService::class);
        $batch = $opening->stage(['batch_key' => uniqid('ai-opening-'), 'source_name' => 'Reviewed opening source',
            'business_date' => '2026-11-12', 'mappings' => [
                ['entity' => 'product', 'source_key' => 'P1', 'target_id' => $product->id],
                ['entity' => 'warehouse', 'source_key' => 'W1', 'target_id' => $warehouse->id],
                ['entity' => 'account', 'source_key' => 'OFFSET', 'target_id' => $offset->id],
            ], 'rows' => [['source_key' => 'STOCK1', 'kind' => 'stock', 'payload' => [
                'product_key' => 'P1', 'warehouse_key' => 'W1', 'qty' => '10', 'unit_cost' => '5', 'offset_account_key' => 'OFFSET',
            ]]], 'expected' => ['stock_qty' => '10', 'stock_value' => '50', 'ar' => '0', 'ap' => '0'],
        ], $context, $this->actor->id);
        $batch = $opening->validate($batch->id, $context, $this->actor->id);
        $this->assertSame('validated', $batch->status, json_encode($batch->validation_errors));
        $proposal = app(ActionProposalService::class)->create($principal, 'setup.opening_stock.create.v1', ['batch_id' => $batch->id]);
        app(ActionApprovalService::class)->approve($principal, $proposal['id'], 1);
        $result = app(ApprovedActionExecutor::class)->execute($principal, $proposal['id'], 1);
        $this->assertSame('opening_import', $result['receipt']['resource_type']);
        $this->assertSame('committed', $batch->fresh()->status);
        $this->assertEquals(10, $product->fresh()->qty);
        $repeat = app(ApprovedActionExecutor::class)->execute($principal, $proposal['id'], 1);
        $this->assertSame($result['receipt']['resource_id'], $repeat['receipt']['resource_id']);
    }

    public function test_validated_financial_opening_batch_posts_without_editing_account_balances_directly(): void
    {
        [$context, $principal, $warehouse, $product, $offset] = $this->cleanOpeningTarget();
        $opening = app(\App\Services\Deployment\OpeningImportService::class);
        $debit = ChartOfAccount::forceCreate(['company_id' => $context->companyId, 'code' => uniqid('AI-'), 'name' => 'Opening Asset',
            'type' => 'asset', 'sub_type' => 'operating_expense', 'control_type' => 'none', 'allow_manual_posting' => true, 'is_active' => true]);
        $credit = ChartOfAccount::forceCreate(['company_id' => $context->companyId, 'code' => uniqid('AI-'), 'name' => 'Opening Equity',
            'type' => 'equity', 'sub_type' => 'operating_expense', 'control_type' => 'none', 'allow_manual_posting' => true, 'is_active' => true]);
        $batch = $opening->stage(['batch_key' => uniqid('ai-financial-opening-'), 'source_name' => 'Reviewed financial source',
            'business_date' => '2026-11-12', 'mappings' => [
                ['entity' => 'account', 'source_key' => 'ASSET', 'target_id' => $debit->id],
                ['entity' => 'account', 'source_key' => 'EQUITY', 'target_id' => $credit->id],
            ], 'rows' => [['source_key' => 'JOURNAL1', 'kind' => 'journal', 'payload' => ['items' => [
                ['account_key' => 'ASSET', 'debit' => '100.1234'], ['account_key' => 'EQUITY', 'credit' => '100.1234'],
            ]]]], 'expected' => ['stock_qty' => '0', 'stock_value' => '0', 'ar' => '0', 'ap' => '0'],
        ], $context, $this->actor->id);
        $batch = $opening->validate($batch->id, $context, $this->actor->id);
        $this->assertSame('validated', $batch->status, json_encode($batch->validation_errors));
        $proposal = app(ActionProposalService::class)->create($principal, 'setup.opening_balance.create.v1', ['batch_id' => $batch->id]);
        $this->assertSame('0.0000', $debit->fresh()->current_balance);
        app(ActionApprovalService::class)->approve($principal, $proposal['id'], 1);
        app(ApprovedActionExecutor::class)->execute($principal, $proposal['id'], 1);
        $this->assertSame('100.1234', $debit->fresh()->current_balance);
        $this->assertSame('0.0000', $debit->fresh()->opening_balance);
        $this->assertTrue(DB::table('journal_entries')->where('company_id', $context->companyId)
            ->where('reference_type', 'migration_opening')->where('reference_id', $batch->id)->where('status', 'posted')->exists());
    }

    public function test_opening_spreadsheet_dry_run_prepares_exact_rows_and_rechecks_revoked_document_before_posting(): void
    {
        [$context, $basePrincipal, $warehouse, $product, $offset] = $this->cleanOpeningTarget();
        config(['ai_documents.enabled' => true]);
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->mock(\App\Ai\Documents\VirusScanner::class)->shouldReceive('assertClean')->andReturnNull();
        $principal = new AiPrincipal(actorId: $basePrincipal->actorId, companyId: $context->companyId,
            branchId: $context->branchId, financialYearId: $context->financialYearId,
            permittedToolNames: ['setup.opening_stock.create.v1']);
        $payload = ['product_key' => 'P1', 'warehouse_key' => 'W1', 'qty' => '10', 'unit_cost' => '5', 'offset_account_key' => 'OFFSET'];
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['source_key', 'kind', 'payload'], escape: '');
        fputcsv($stream, ['STOCK1', 'stock', json_encode($payload, JSON_THROW_ON_ERROR)], escape: '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $documents = app(\App\Ai\Documents\DocumentIntakeService::class);
        $document = $documents->upload($principal, \Illuminate\Http\UploadedFile::fake()->createWithContent('opening.csv', $csv), 'opening_import');
        $documents->process($principal, $document['id']);
        $source = ['batch_key' => 'spreadsheet-opening-source', 'source_name' => 'Reviewed stock opening', 'business_date' => '2026-11-12',
            'mappings' => [
                ['entity' => 'product', 'source_key' => 'P1', 'target_id' => $product->id],
                ['entity' => 'warehouse', 'source_key' => 'W1', 'target_id' => $warehouse->id],
                ['entity' => 'account', 'source_key' => 'OFFSET', 'target_id' => $offset->id],
            ], 'expected' => ['stock_qty' => '10', 'stock_value' => '50', 'ar' => '0', 'ap' => '0']];
        $result = app(\App\Ai\Imports\OpeningImportPlanner::class)->prepareDocument($principal, 'setup.opening_stock.create.v1', $document['id'], $source);
        $this->assertSame('ok', $result['status']);
        $this->assertEquals(array_replace($payload, ['qty' => '10.0000', 'unit_cost' => '5.0000']), $result['data']['rows'][0]['payload']);
        $this->assertSame('10.0000', $result['data']['rows'][0]['payload']['qty']);
        $this->assertSame('5.0000', $result['data']['rows'][0]['payload']['unit_cost']);
        $this->assertCount(3, $result['data']['mappings']);
        $this->assertEquals(0, $product->fresh()->qty);
        $this->assertFalse(DB::table('journal_entries')->where('company_id', $context->companyId)->where('status', 'posted')->exists());
        $this->assertDatabaseHas('ai_opening_import_sources', ['company_id' => $context->companyId,
            'batch_id' => $result['batch_id'], 'document_id' => $document['id'], 'checksum' => $document['checksum']]);
        app(ActionApprovalService::class)->approve($principal, $result['data']['proposal_id'], 1);
        $documents->revoke($principal, $document['id']);
        try {
            app(ApprovedActionExecutor::class)->execute($principal, $result['data']['proposal_id'], 1);
            $this->fail('Revoked opening document was posted.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertEquals(0, $product->fresh()->qty);
            $this->assertFalse(DB::table('stock_movements')->where('company_id', $context->companyId)->exists());
        }
    }

    public function test_opening_source_tool_stages_and_previews_without_financial_or_stock_mutations(): void
    {
        [$context, $basePrincipal, $warehouse, $product, $offset] = $this->cleanOpeningTarget();
        $principal = new AiPrincipal(actorId: $basePrincipal->actorId, companyId: $context->companyId,
            branchId: $context->branchId, financialYearId: $context->financialYearId,
            permittedToolNames: ['setup.opening_stock.create.v1']);
        $source = ['batch_key' => 'typed-opening-source', 'source_name' => 'Reviewed opening source',
            'business_date' => '2026-11-12', 'mappings' => [
                ['entity' => 'product', 'source_key' => 'P1', 'target_id' => $product->id],
                ['entity' => 'warehouse', 'source_key' => 'W1', 'target_id' => $warehouse->id],
                ['entity' => 'account', 'source_key' => 'OFFSET', 'target_id' => $offset->id],
            ], 'rows' => [['source_key' => 'STOCK1', 'kind' => 'stock', 'payload' => [
                'product_key' => 'P1', 'warehouse_key' => 'W1', 'qty' => '10', 'unit_cost' => '5', 'offset_account_key' => 'OFFSET',
            ]]], 'expected' => ['stock_qty' => '10', 'stock_value' => '50', 'ar' => '0', 'ap' => '0']];
        $tool = new \App\Ai\Imports\PrepareOpeningImport($principal);
        $result = json_decode($tool->handle(new \Laravel\Ai\Tools\Request([
            'action_key' => 'setup.opening_stock.create.v1', 'source_json' => json_encode($source, JSON_THROW_ON_ERROR),
        ])), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $result['status'], json_encode($result));
        $this->assertEquals(0, $product->fresh()->qty);
        $this->assertFalse(DB::table('stock_movements')->where('company_id', $context->companyId)->exists());
        $this->assertDatabaseHas('ai_action_proposals', ['id' => $result['data']['proposal_id'], 'status' => 'pending']);
        app(ActionApprovalService::class)->approve($principal, $result['data']['proposal_id'], 1);
        $receipt = app(ApprovedActionExecutor::class)->execute($principal, $result['data']['proposal_id'], 1);
        $this->assertSame('opening_import', $receipt['receipt']['resource_type']);
        $this->assertEquals(10, $product->fresh()->qty);
    }

    private function cleanOpeningTarget(): array
    {
        $company = Company::forceCreate([
            'code' => uniqid('OP-'), 'legal_name' => 'Clean Opening Target',
            'country_code' => 'IN', 'timezone' => 'Asia/Kolkata', 'status' => 'active',
        ]);
        $branch = CompanyBranch::forceCreate([
            'company_id' => $company->id, 'name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true,
        ]);
        $year = FiscalYear::forceCreate([
            'company_id' => $company->id, 'name' => '2026-2027', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open',
        ]);
        $company->users()->attach($this->actor->id);
        DB::table('company_user_branches')->insert([
            'company_id' => $company->id, 'user_id' => $this->actor->id, 'branch_id' => $branch->id,
        ]);
        app(AiFeatureGate::class)->setCompanySetting($company->id, true, false);

        $context = new CompanyContext($company->id, $branch->id, $year->id);
        $principal = new AiPrincipal(actorId: $this->actor->id, companyId: $company->id, branchId: $branch->id, financialYearId: $year->id);

        $warehouse = Warehouse::forceCreate([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'name' => 'Opening Warehouse', 'address' => 'Test', 'is_active' => true,
        ]);
        $unit = Unit::forceCreate([
            'company_id' => $company->id, 'unit_code' => 'PCS', 'unit_name' => 'Pieces', 'is_active' => true,
        ]);
        $category = Category::where('company_id', $company->id)->first();
        if (!$category) {
            $category = Category::forceCreate([
                'company_id' => $company->id, 'name' => 'Opening Category', 'is_active' => true,
            ]);
        }
        $product = Product::forceCreate([
            'company_id' => $company->id, 'code' => uniqid('OP-'), 'name' => 'Opening Product',
            'type' => 'standard', 'unit_id' => $unit->id, 'purchase_unit_id' => $unit->id, 'sale_unit_id' => $unit->id,
            'category_id' => $category->id, 'barcode_symbology' => 'code128',
            'qty' => 0, 'cost' => 5, 'price' => 10, 'is_batch' => false, 'is_imei' => false, 'is_variant' => false,
        ]);
        $invAccount = ChartOfAccount::forceCreate([
            'company_id' => $company->id, 'code' => uniqid('INV-'), 'name' => 'Inventory Asset',
            'type' => 'asset', 'sub_type' => 'inventory', 'control_type' => 'none',
            'allow_manual_posting' => true, 'is_active' => true,
        ]);
        SemanticAccountMapping::forceCreate([
            'company_id' => $company->id, 'semantic_role' => 'inventory', 'account_id' => $invAccount->id,
            'account_code' => $invAccount->code, 'account_name' => $invAccount->name,
            'category' => 'core', 'description' => 'Inventory',
        ]);

        $offset = ChartOfAccount::forceCreate([
            'company_id' => $company->id, 'code' => uniqid('OFFSET-'), 'name' => 'Opening Balance Equity',
            'type' => 'equity', 'sub_type' => 'opening_balance', 'control_type' => 'none',
            'allow_manual_posting' => true, 'is_active' => true,
        ]);
        SemanticAccountMapping::forceCreate([
            'company_id' => $company->id, 'semantic_role' => 'opening_balance', 'account_id' => $offset->id,
            'account_code' => $offset->code, 'account_name' => $offset->name,
            'category' => 'core', 'description' => 'Opening Balance',
        ]);

        return [$context, $principal, $warehouse, $product, $offset];
    }

    private function enableOperations(): void
    {
        config(['operations.enabled' => true, 'app.erp_optional_activation_ready' => true]);
        foreach (['manufacturing.bom', 'manufacturing.production', 'operations.job_work'] as $capability) {
            app(\App\Services\Platform\CapabilityService::class)->enable($capability, [], $this->context, $this->actor->id);
        }
    }

    private function account(string $name, string $type): ChartOfAccount
    {
        return ChartOfAccount::forceCreate(['company_id' => $this->context->companyId, 'code' => uniqid('AI-'), 'name' => $name,
            'type' => $type, 'sub_type' => 'operating_expense', 'control_type' => 'none', 'allow_manual_posting' => true, 'is_active' => true]);
    }

    private function opening(float $qty): void
    {
        app(InventoryMovementService::class)->receive(new StockMovementCommand(date: '2026-11-12',
            lines: [new StockLine(productId: $this->product->id, qty: $qty, unitCost: 5, uomId: $this->product->unit_id)],
            warehouseId: $this->source->id, context: $this->context, userId: $this->actor->id));
    }

    private function transfer(int $qty): array
    {
        return ['business_date' => '2026-11-12', 'warehouse_id' => $this->source->id,
            'to_warehouse_id' => $this->destination->id, 'reason' => 'Reviewed warehouse relocation',
            'lines' => [['product_id' => $this->product->id, 'qty' => (string) $qty, 'uom_id' => $this->product->unit_id]]];
    }

    private function rejectPreview(string $key, array $data, string $message): void
    {
        try {
            app(ExtendedActionAdapter::class)->preview(app(ActionCapabilityRegistry::class)->get($key), $data, $this->context);
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
        }
    }
}
