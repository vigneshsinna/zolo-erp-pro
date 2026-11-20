<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Conversation\ConversationalSlotInterpreter;
use App\Ai\Conversation\VoiceIntentResolver;
use App\Ai\Services\PurchaseBillGstIntentService;
use PHPUnit\Framework\TestCase;

final class PurchaseDocumentCorrectionInterpreterTest extends TestCase
{
    private function task(int $count = 1): array
    {
        return [
            'action_key' => 'purchase_bill.create.v1',
            'slots_json' => json_encode(['items' => array_map(
                static fn (int $i): array => [
                    'client_line_id' => 'line-'.$i, 'product_id' => 100 + $i,
                    'qty' => '10.00', 'net_unit_cost' => '200.00',
                ], range(0, $count - 1),
            )], JSON_THROW_ON_ERROR),
        ];
    }

    public function test_po_and_purchase_bill_intents_are_distinct_and_ambiguous_speech_does_not_write(): void
    {
        $classifier = new VoiceIntentResolver();
        $this->assertSame('purchase_order.create.v1', $classifier->resolve('Create a purchase order'));
        $this->assertSame('purchase_bill.create.v1', $classifier->resolve('Prepare a purchase bill'));
        $this->assertNull($classifier->resolve('Create a purchase order and purchase bill'));
        $this->assertNull($classifier->resolve('Please show the purchase bill'));
    }

    public function test_supplier_warehouse_and_quantity_can_be_corrected_in_one_chat_message(): void
    {
        $parsed = (new ConversationalSlotInterpreter())->interpret($this->task(),
            'change supplier to ABC Traders and set warehouse to Lenz Main and quantity to 25');
        $this->assertSame('continue_task', $parsed['turn_intent']);
        $this->assertSame([
            ['field' => 'supplier_query', 'value' => 'ABC Traders', 'confirmation' => 'user_text'],
            ['field' => 'warehouse_query', 'value' => 'Lenz Main', 'confirmation' => 'user_text'],
            ['field' => 'items[line-0].qty', 'value' => '25', 'confirmation' => 'user_text'],
        ], $parsed['corrections']);
    }

    public function test_bill_references_and_dates_are_parsed_as_explicit_values(): void
    {
        $parsed = (new ConversationalSlotInterpreter())->interpret($this->task(),
            'invoice number to INV-003; bill date to 2026-11-20; due date to 2026-12-05; PO reference 123; GRN 44; discount 50');
        $this->assertSame('continue_task', $parsed['turn_intent']);
        $this->assertSame([
            'supplier_invoice_no', 'date', 'due_date', 'purchase_order_id',
            'grn_id', 'order_discount',
        ], array_column($parsed['corrections'], 'field'));
        $this->assertSame('INV-003', $parsed['corrections'][0]['value']);
        $this->assertSame(123, $parsed['corrections'][3]['value']);
        $this->assertSame(44, $parsed['corrections'][4]['value']);
    }

    public function test_multi_line_quantity_needs_an_explicit_line_number(): void
    {
        $interpreter = new ConversationalSlotInterpreter();
        $ambiguous = $interpreter->interpret($this->task(2), 'quantity to 25');
        $this->assertSame('unknown', $ambiguous['turn_intent']);
        $this->assertSame([], $ambiguous['corrections']);
        $specific = $interpreter->interpret($this->task(2), 'item 2 quantity to 25');
        $this->assertSame('items[line-1].qty', $specific['corrections'][0]['field']);
        $this->assertSame('25', $specific['corrections'][0]['value']);
        $nonexistent = $interpreter->interpret($this->task(2), 'item 3 quantity to 25');
        $this->assertSame([], $nonexistent['corrections']);
    }

    public function test_chat_cannot_forge_supplier_ids_or_disable_gst_by_zero_rate_text(): void
    {
        $interpreter = new ConversationalSlotInterpreter();
        foreach (['set GST to zero', 'supplier ID 9999', 'set tax rate to zero'] as $message) {
            $result = $interpreter->interpret($this->task(), $message);
            $this->assertSame([], $result['corrections'], $message);
            $this->assertSame('unknown', $result['turn_intent'], $message);
        }
        $gst = new PurchaseBillGstIntentService();
        $this->assertSame('non_gst', $gst->detect('this is a non-GST bill'));
        $this->assertSame('gst', $gst->detect('this is a GST bill'));
        $this->assertNull($gst->detect('update purchase bill warehouse to main'));
    }
}
