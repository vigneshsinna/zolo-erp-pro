<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Optical;

use App\Ai\Knowledge\PurchaseDocumentClassifier;
use App\Ai\Knowledge\SalesDocumentClassifier;
use App\Services\Optical\OpticalSpecNormalizer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class OpticalLensIdentityAndClassifierTest extends TestCase
{
    public function test_signed_power_normalization_never_confuses_plus_minus_or_nearby_steps(): void
    {
        $minusTwo = OpticalSpecNormalizer::normalizePower('-2', 'sph');
        $plusTwo = OpticalSpecNormalizer::normalizePower('2.0', 'sph');
        $minusQuarterMore = OpticalSpecNormalizer::normalizePower(-2.25, 'sph');
        $plano = OpticalSpecNormalizer::normalizePower('PLANO', 'sph');

        $this->assertSame('-2.00', $minusTwo);
        $this->assertSame('+2.00', $plusTwo);
        $this->assertSame('-2.25', $minusQuarterMore);
        $this->assertSame('0.00', $plano);

        $sigA = OpticalSpecNormalizer::normalizeSpec([
            'lens_family' => 'Essilor SV 1.67',
            'sph' => '-2',
            'cyl' => '-0.5',
            'axis' => '90',
            'lr' => 'R',
        ])['signature'];
        $sigB = OpticalSpecNormalizer::normalizeSpec([
            'lens_family' => 'Essilor SV 1.67',
            'sph' => '+2.00',
            'cyl' => '-0.50',
            'axis' => 90,
            'lr' => 'RE',
        ])['signature'];
        $sigSame = OpticalSpecNormalizer::normalizeSpec([
            'lens_family' => 'Essilor SV 1.67',
            'sph' => '-2.00',
            'cyl' => '-0.50',
            'axis' => 90,
            'lr' => 'RE',
        ])['signature'];

        $this->assertSame($sigA, $sigSame);
        $this->assertNotSame($sigA, $sigB);
    }

    public function test_out_of_range_power_and_invalid_axis_are_rejected(): void
    {
        $rejectedPower = false;
        try {
            OpticalSpecNormalizer::normalizePower('-55.00', 'sph');
        } catch (ValidationException) {
            $rejectedPower = true;
        }
        $this->assertTrue($rejectedPower);

        $this->expectException(ValidationException::class);
        OpticalSpecNormalizer::normalizeAxis('185');
    }

    public function test_sales_and_purchase_document_classifiers_route_optical_and_fail_closed_cases(): void
    {
        $sales = new SalesDocumentClassifier();
        $purchase = new PurchaseDocumentClassifier();

        $s2 = $sales->classify('create dc against sales order', ['sales_order_id' => 10]);
        $this->assertSame('delivery_challan', $s2['classification']);
        $this->assertSame('delivery_challan.create.v1', $s2['candidate_action']);

        $s1 = $sales->classify('direct dealer invoice for 10 pcs -1.25 lenses');
        $this->assertSame('direct_sales_invoice', $s1['classification']);
        $this->assertSame('sales_invoice.create.v1', $s1['candidate_action']);

        $rxBlocked = $sales->classify('custom rx lab job', ['has_lab_capability' => false]);
        $this->assertSame('rx_lab_job', $rxBlocked['classification']);
        $this->assertNull($rxBlocked['candidate_action']);

        $p1Direct = $purchase->classify('direct supplier bill without po for lenses');
        $this->assertSame('goods_bill', $p1Direct['classification']);
        $this->assertFalse($p1Direct['po_required']);
        $this->assertSame('purchase_bill.create.v1', $p1Direct['candidate_action']);

        $p5Blocked = $purchase->classify('supplier bill', ['receipt_status' => 'not_received']);
        $this->assertSame('invoice_before_goods', $p5Blocked['classification']);
        $this->assertFalse($p5Blocked['supported_without_clarification']);
    }
}
