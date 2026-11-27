<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Knowledge;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Knowledge\BusinessKnowledgeRegistry;
use App\Ai\Knowledge\BusinessWorkflowPlanner;
use App\Ai\Knowledge\BusinessWorkflowProgress;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\ActionSecurity;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class KnowledgePlannerHardeningTest extends TestCase
{
    use UsesAiMysql;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'erp_ai.enabled' => true,
            'erp_ai.read_only' => false,
            'ai_actions.foundation_enabled' => true,
            'ai_business_knowledge.enabled' => true,
            'ai_business_knowledge.planner_enabled' => true,
            'ai_business_knowledge.workflow_enabled' => true,
            'ai_business_knowledge.opticals_enabled' => false,
            'commercial.enabled' => true,
        ]);

        $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'zolo_kpack_'.Str::random(10);
        File::makeDirectory($this->tempRoot.'/resources/ai/knowledge', 0755, true);
        File::makeDirectory($this->tempRoot.'/app/Services/Commercial', 0755, true);
        File::put($this->tempRoot.'/app/Services/Commercial/CommercialApplicationService.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->tempRoot) && File::isDirectory($this->tempRoot)) {
            File::deleteDirectory($this->tempRoot);
        }
        parent::tearDown();
    }

    public function test_reviewed_pack_supports_optional_skip_and_blocks_knowledge_hash_drift(): void
    {
        [$principal] = $this->createActorAndPrincipal();

        $this->writeTempPack('Initial approved sales invoice summary.');

        $registry = new BusinessKnowledgeRegistry($this->tempRoot);
        $security = app(ActionSecurity::class);
        $capabilities = app(ActionCapabilityRegistry::class);
        $proposals = app(ActionProposalService::class);
        $planner = new BusinessWorkflowPlanner($registry, $security, $capabilities);
        $progress = new BusinessWorkflowProgress($security, $proposals, $planner);

        $preview = $planner->preview($principal, 'process.direct_sale_test.v1');
        $this->assertTrue($preview['can_start']);
        $this->assertSame('reviewed', $preview['definition_status']);

        $started = $planner->start($principal, 'process.direct_sale_test.v1');
        $this->assertFalse($started['knowledge_drifted']);

        // Required downstream/blocked or required step cannot be skipped.
        try {
            $progress->skipNotApplicableStep(
                $principal,
                $started['workflow_id'],
                $started['revision'],
                'issue_invoice',
                'Trying to skip required step',
            );
            $this->fail('Required workflow step skip must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('step', $e->errors());
        }

        // Skipping explicitly optional predecessor succeeds and unblocks downstream required step.
        $afterSkip = $progress->skipNotApplicableStep(
            $principal,
            $started['workflow_id'],
            $started['revision'],
            'optional_quote',
            'Direct walk-in sale skips prior order/DC route',
        );
        $stepsByKey = collect($afterSkip['steps'])->keyBy('step_key');
        $this->assertSame('skipped_not_applicable', $stepsByKey['optional_quote']['status']);
        $this->assertSame('awaiting_proposal', $stepsByKey['issue_invoice']['status']);

        // Mutate reviewed pack definition -> live knowledge_hash drifts -> progress progression must block.
        $this->writeTempPack('Updated process knowledge after deployment.');
        $driftedRegistry = new BusinessKnowledgeRegistry($this->tempRoot);
        $driftedPlanner = new BusinessWorkflowPlanner($driftedRegistry, $security, $capabilities);
        $driftedProgress = new BusinessWorkflowProgress($security, $proposals, $driftedPlanner);

        $inspected = $driftedPlanner->inspect($principal, $started['workflow_id']);
        $this->assertTrue($inspected['knowledge_drifted']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('knowledge hash has drifted');
        $driftedProgress->reconcile($principal, $started['workflow_id'], $afterSkip['revision']);
    }

    public function test_optical_workflow_fails_closed_for_non_optical_company_or_disabled_flag(): void
    {
        [$principal, $company, $branch, $fy, $user] = $this->createActorAndPrincipal();

        $planner = app(BusinessWorkflowPlanner::class);
        $preview = $planner->preview($principal, 'process.opticals.direct_stock_sale.v1');
        $this->assertFalse($preview['can_start']);

        // Even if a workflow row exists in DB, progress checks fail closed when opticals_enabled is false.
        $workflowId = (string) Str::uuid();
        DB::table('ai_business_workflows')->insert([
            'id' => $workflowId,
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'financial_year_id' => $fy->id,
            'actor_id' => $user->id,
            'process_id' => 'process.opticals.direct_stock_sale.v1',
            'knowledge_hash' => $planner->currentKnowledgeHash(),
            'status' => 'planned',
            'revision' => 1,
            'facts_json' => '{}',
            'expires_at' => now('UTC')->addDays(5),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $this->expectException(AuthorizationException::class);
        app(BusinessWorkflowProgress::class)->reconcile($principal, $workflowId, 1);
    }

    private function writeTempPack(string $processSummary): void
    {
        File::put($this->tempRoot.'/resources/ai/knowledge/source_manifest.json', json_encode([
            'schema_version' => 1,
            'sources' => ['app/Services/Commercial/CommercialApplicationService.php'],
        ], JSON_THROW_ON_ERROR));

        File::put($this->tempRoot.'/resources/ai/knowledge/definitions.json', json_encode([
            'schema_version' => 2,
            'definitions' => [
                [
                    'id' => 'action.sales_invoice.create.v1',
                    'kind' => 'action',
                    'version' => 1,
                    'status' => 'reviewed',
                    'owner' => 'sales',
                    'summary' => 'Governed sales invoice preparation',
                    'action_key' => 'sales_invoice.create.v1',
                    'effective_from' => '2026-01-01',
                    'source_refs' => ['app/Services/Commercial/CommercialApplicationService.php'],
                    'reviewed_at' => '2026-11-26T00:00:00Z',
                    'reviewed_by' => 'owner@zoloerp.test',
                    'domain_policy_ref' => 'policy.sales_invoice_reviewed.v1',
                    'signoff_evidence' => ['signoff_id' => 'SIG-TEST-1'],
                    'impacted_workflows' => ['process.direct_sale_test.v1'],
                    'rollback_policy' => 'Cancel draft or reverse posted invoice via sales return.',
                ],
                [
                    'id' => 'policy.sales_invoice_reviewed.v1',
                    'kind' => 'policy',
                    'version' => 1,
                    'status' => 'reviewed',
                    'owner' => 'sales',
                    'summary' => 'Requires explicit human confirmation.',
                    'effective_from' => '2026-01-01',
                    'source_refs' => ['app/Services/Commercial/CommercialApplicationService.php'],
                    'reviewed_at' => '2026-11-26T00:00:00Z',
                    'reviewed_by' => 'owner@zoloerp.test',
                    'domain_policy_ref' => 'policy.sales_invoice_reviewed.v1',
                    'signoff_evidence' => ['signoff_id' => 'SIG-TEST-1'],
                    'impacted_workflows' => ['process.direct_sale_test.v1'],
                    'rollback_policy' => 'No direct DB writes.',
                ],
                [
                    'id' => 'process.direct_sale_test.v1',
                    'kind' => 'process',
                    'version' => 1,
                    'status' => 'reviewed',
                    'owner' => 'sales',
                    'summary' => $processSummary,
                    'effective_from' => '2026-01-01',
                    'source_refs' => ['app/Services/Commercial/CommercialApplicationService.php'],
                    'reviewed_at' => '2026-11-26T00:00:00Z',
                    'reviewed_by' => 'owner@zoloerp.test',
                    'domain_policy_ref' => 'policy.sales_invoice_reviewed.v1',
                    'signoff_evidence' => ['signoff_id' => 'SIG-TEST-1'],
                    'impacted_workflows' => ['process.direct_sale_test.v1'],
                    'rollback_policy' => 'Reverse linked invoice if cancelled.',
                    'steps' => [
                        [
                            'id' => 'optional_quote',
                            'kind' => 'read_or_clarify',
                            'depends_on' => [],
                            'optional' => true,
                        ],
                        [
                            'id' => 'issue_invoice',
                            'kind' => 'reviewed_action',
                            'action_key' => 'sales_invoice.create.v1',
                            'depends_on' => ['optional_quote'],
                            'optional' => false,
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{0: AiPrincipal, 1: Company, 2: CompanyBranch, 3: FiscalYear, 4: User} */
    private function createActorAndPrincipal(): array
    {
        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $user = User::create([
            'name' => 'Planner Hardening User',
            'email' => 'planhard_'.Str::random(8).'@example.test',
            'phone' => '98888'.rand(10000, 99999),
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $company = Company::create([
            'legal_name' => 'Hardening Trading Co',
            'code' => 'HTC_'.Str::random(6),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);
        app(AiFeatureGate::class)->setCompanySetting($company->id, true, false);

        $branch = $company->branches()->create([
            'code' => 'HB1',
            'name' => 'Main Branch',
            'branch_type' => 'branch',
            'is_active' => true,
        ]);

        $fy = FiscalYear::create([
            'company_id' => $company->id,
            'code' => 'FY2627',
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_closed' => false,
        ]);

        $company->users()->syncWithoutDetaching([$user->id => ['is_default' => true]]);
        DB::table('company_user_branches')->insert([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'branch_id' => $branch->id,
        ]);

        $principal = app(AiPrincipalFactory::class)->createForUser(
            $user->id,
            $company->id,
            $branch->id,
            $fy->id,
        );

        return [$principal, $company, $branch, $fy, $user];
    }
}
