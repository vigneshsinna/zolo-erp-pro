<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Knowledge\JobWorkDirectionClassifier;
use App\Ai\Registries\ExtendedActionCatalog;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Schemas\CreateJobWorkServiceBillInputSchema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class JobWorkBusinessKnowledgeTest extends TestCase
{
    public function test_material_ownership_is_never_assumed_from_job_work_alone(): void
    {
        $classifier = new JobWorkDirectionClassifier;
        $this->assertSame('ambiguous', $classifier->classify('Create job work bill')['direction']);
        $this->assertTrue($classifier->classify('Dyeing complete')['needs_clarification']);
        $this->assertSame('company_owned_outward', $classifier->classify('Send fabric to the job worker')['direction']);
        $this->assertSame('customer_owned_inward', $classifier->classify('Customer supplied their fabric for dyeing')['direction']);
    }

    public function test_sensitive_job_work_actions_are_absent_by_default(): void
    {
        config(['ai_business_knowledge.job_work_enabled' => false]);
        $keys = array_map(static fn ($d): string => $d->key, ExtendedActionCatalog::definitions());
        $this->assertNotContains('job_work.order.create.v1', $keys);
        $this->assertNotContains('job_work.service_bill.create.v1', $keys);
        $this->assertNotContains('principal_job.service_invoice.create.v1', $keys);
    }

    public function test_new_actions_have_separate_human_approval_and_auth_context(): void
    {
        config(['ai_business_knowledge.job_work_enabled' => true]);
        $defs = collect(ExtendedActionCatalog::definitions())->keyBy('key');
        foreach (['job_work.order.create.v1', 'job_work.service_bill.create.v1',
                  'principal_job.service_invoice.create.v1'] as $key) {
            $this->assertTrue($defs->has($key));
            $this->assertTrue($defs[$key]->requiresApproval);
            $this->assertTrue($defs[$key]->requiresFreshSnapshot);
            $this->assertFalse($defs[$key]->enabledByDefault);
        }
        $this->assertSame('r3_financial', $defs['job_work.service_bill.create.v1']->riskTier);
        $this->assertTrue((new ActionRequirementsRegistry)->has('job_work.service_bill.create.v1'));
    }

    public function test_supplier_processing_bill_requires_actual_receipt_service_and_invoice(): void
    {
        $rules = (new CreateJobWorkServiceBillInputSchema)->rules();
        $this->assertTrue(Validator::make([], $rules)->fails());
        $this->assertTrue(Validator::make([
            'receipt_id' => 1, 'business_date' => '2026-11-20',
            'supplier_invoice_no' => 'J-001', 'supplier_invoice_date' => '2026-11-20',
            'charge_mode' => 'capitalise',
            'items' => [['product_id' => 1, 'qty' => '10.00', 'net_unit_cost' => '15.00']],
        ], $rules)->passes());
        $this->assertTrue(Validator::make([
            'receipt_id' => 1, 'business_date' => '2026-11-20',
            'supplier_invoice_no' => 'J-001', 'supplier_invoice_date' => '2026-11-20',
            'charge_mode' => 'unknown',
            'items' => [['product_id' => 1, 'qty' => '-10', 'net_unit_cost' => '15']],
        ], $rules)->fails());
    }
}
