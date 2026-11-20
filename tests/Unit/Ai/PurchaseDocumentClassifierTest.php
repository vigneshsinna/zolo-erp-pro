<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Knowledge\PurchaseDocumentClassifier;
use Tests\TestCase;

final class PurchaseDocumentClassifierTest extends TestCase
{
    public function test_clear_goods_service_expense_and_job_work_requests_remain_distinct(): void
    {
        $classifier = new PurchaseDocumentClassifier;
        $cases = [
            'Create a purchase order for linen' => 'purchase_order',
            'Create PO for fabric' => 'purchase_order',
            'Received goods from ABC Traders' => 'goods_bill',
            'Create a supplier invoice for stock' => 'goods_bill',
            'Book a service bill for consultancy' => 'service_bill',
            'Book our electricity bill' => 'expense_bill',
            'Book dyeing charges for returned fabric' => 'job_work_processing_bill',
            'Create a job work processing bill' => 'job_work_processing_bill',
            'Create a bill' => 'ambiguous',
            'Non-GST bill' => 'ambiguous',
        ];
        foreach ($cases as $text => $expected) {
            $this->assertSame($expected, $classifier->classify($text)['classification'], $text);
        }
    }

    public function test_non_gst_wording_is_not_a_tax_decision(): void
    {
        $result = (new PurchaseDocumentClassifier)->classify('Service bill non-GST');
        $this->assertTrue($result['needs_gst_clarification']);
        $this->assertNull((new PurchaseDocumentClassifier)->classify('Book electricity bill')['candidate_action']);
    }

    public function test_job_work_receipt_overrides_generic_service_guess(): void
    {
        $classified = (new PurchaseDocumentClassifier)->classify('book supplier charges', [
            'job_work_receipt_id' => 9,
        ]);
        $this->assertSame('job_work_processing_bill', $classified['classification']);
        $this->assertNull($classified['candidate_action']);
    }
}
