<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Conversation;

use App\Ai\Conversation\ConversationalSlotInterpreter;
use PHPUnit\Framework\TestCase;

final class ConversationalSlotInterpreterTest extends TestCase
{
    public function test_multi_slot_reply_keeps_unrelated_lines_and_decimal_strings(): void
    {
        $task = ['slots_json' => json_encode(['items' => [['client_line_id' => 'original', 'product_id' => 7, 'qty' => '2.00']]])];
        $result = (new ConversationalSlotInterpreter)->interpret($task, 'ABC Traders; add 100 pairs of Cotton Shirt Blue at 480');
        $this->assertSame('continue_task', $result['turn_intent']);
        $this->assertSame('supplier_query', $result['corrections'][0]['field']);
        $this->assertSame('ABC Traders', $result['corrections'][0]['value']);
        $lines = $result['corrections'][1]['value'];
        $this->assertSame('original', $lines[0]['client_line_id']);
        $this->assertSame('100', $lines[1]['qty']);
        $this->assertSame('480', $lines[1]['net_unit_cost']);
        $this->assertSame('pairs', $lines[1]['uom']);
    }

    public function test_change_and_remove_use_stable_line_ids(): void
    {
        $task = ['slots_json' => json_encode(['items' => [['client_line_id' => 'alpha', 'qty' => '5'], ['client_line_id' => 'beta', 'qty' => '8']]])];
        $result = (new ConversationalSlotInterpreter)->interpret($task, 'remove the second item');
        $this->assertSame('items[beta]._destroy', $result['corrections'][0]['field']);
        $single = ['slots_json' => json_encode(['items' => [['client_line_id' => 'alpha', 'qty' => '5']]])];
        $result = (new ConversationalSlotInterpreter)->interpret($single, 'make it 120 instead');
        $this->assertSame(['field' => 'items[alpha].qty', 'value' => '120', 'confirmation' => 'user_text'], $result['corrections'][0]);
    }

    public function test_ambiguous_yes_and_uncertain_numbers_do_not_patch(): void
    {
        foreach (['yes', '50 not 15', 'fifteen or fifty'] as $message) {
            $result = (new ConversationalSlotInterpreter)->interpret(['slots_json' => '{}'], $message);
            $this->assertSame([], $result['corrections']);
        }
    }

    public function test_multi_field_single_turn_extracts_product_qty_supplier_and_warehouse(): void
    {
        $task = ['slots_json' => '{}'];
        $interpreter = new ConversationalSlotInterpreter;
        $result = $interpreter->interpret($task, 'Create a PO for 200 metres of linen from New Trader, delivered to Erode warehouse.');
        $this->assertSame('continue_task', $result['turn_intent']);
        $this->assertCount(3, $result['corrections']);

        $fields = array_column($result['corrections'], 'field');
        $this->assertContains('items', $fields);
        $this->assertContains('supplier_query', $fields);
        $this->assertContains('warehouse_query', $fields);

        $supplierCorr = collect($result['corrections'])->firstWhere('field', 'supplier_query');
        $this->assertSame('New Trader', $supplierCorr['value']);

        $warehouseCorr = collect($result['corrections'])->firstWhere('field', 'warehouse_query');
        $this->assertSame('Erode warehouse', $warehouseCorr['value']);

        $itemsCorr = collect($result['corrections'])->firstWhere('field', 'items');
        $this->assertSame('200', $itemsCorr['value'][0]['qty']);
        $this->assertSame('metres', $itemsCorr['value'][0]['uom']);
        $this->assertSame('linen', $itemsCorr['value'][0]['product_query']);
    }

    public function test_natural_corrections_quantity_separate_line_and_gst_treatment(): void
    {
        $task = [
            'slots_json' => json_encode([
                'items' => [
                    ['client_line_id' => 'line-1', 'product_query' => 'linen', 'qty' => '200', 'uom' => 'metres', 'net_unit_cost' => '150'],
                ],
            ]),
        ];
        $interpreter = new ConversationalSlotInterpreter;
        $result = $interpreter->interpret($task, 'Actually 180 metres and put 20 in a separate line. It is a GST bill.');
        $this->assertSame('continue_task', $result['turn_intent']);

        $fields = array_column($result['corrections'], 'field');
        $this->assertContains('items[line-1].qty', $fields);
        $this->assertContains('items', $fields);
        $this->assertContains('is_non_gst', $fields);

        $qtyCorr = collect($result['corrections'])->firstWhere('field', 'items[line-1].qty');
        $this->assertSame('180', $qtyCorr['value']);

        $gstCorr = collect($result['corrections'])->firstWhere('field', 'is_non_gst');
        $this->assertFalse($gstCorr['value']);

        $itemsCorr = collect($result['corrections'])->firstWhere('field', 'items');
        $this->assertCount(2, $itemsCorr['value']);
        $this->assertSame('20', $itemsCorr['value'][1]['qty']);
        $this->assertSame('metres', $itemsCorr['value'][1]['uom']);
    }
}
