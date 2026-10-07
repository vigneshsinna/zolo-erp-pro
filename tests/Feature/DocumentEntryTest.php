<?php

namespace Tests\Feature;

use App\Services\Commercial\DocumentContextResolver;
use App\Services\Platform\DocumentShortcutRegistry;
use Illuminate\Http\Request;
use Tests\Support\CommercialTestCase;

/** Document accelerators and linked-document references on a controlled fixture company. */
class DocumentEntryTest extends CommercialTestCase
{
    public function test_registry_keys_are_unique_and_follow_the_f2_and_f12_families(): void
    {
        $keys = array_column(app(DocumentShortcutRegistry::class)->definitions(), 'key');
        $this->assertSame($keys, array_unique($keys), 'a key may open only one document');
        $this->assertEqualsCanonicalizing(['F2', 'Shift+F2', 'Alt+F2', 'Alt+F10', 'F12', 'Shift+F12', 'Ctrl+F12', 'Alt+F12'], $keys);
        $this->assertNotContains('Ctrl+F2', $keys, 'Sales Order stays unassigned until it has a proven lifecycle');
        foreach (['F4', 'F5', 'F6', 'F7', 'F9'] as $accountingKey) {
            $this->assertNotContains($accountingKey, $keys, $accountingKey.' belongs to the accounting vouchers');
        }
    }

    public function test_registry_hides_documents_the_user_cannot_add_and_gates_purchase_orders(): void
    {
        $registry = app(DocumentShortcutRegistry::class);
        $this->assertSame([], $registry->forUser(fn () => false, false));
        $sales = $registry->forUser(fn ($permission) => $permission === 'sales-add', false);
        $this->assertSame(['sale'], array_column($sales, 'id'));
        $this->assertArrayNotHasKey('permission', $sales[0], 'permission names stay server-side');

        config(['documents.purchase_order_shortcut' => false]);
        $this->assertNotContains('purchase-order', array_column($registry->forUser(fn () => true, true), 'id'));
        config(['documents.purchase_order_shortcut' => true]);
        $order = $registry->find($registry->forUser(fn () => true, true), 'purchase-order');
        $this->assertSame(route('purchases.index', ['new' => 1, 'doc' => 'order']), $order['url']);
    }

    private function refs(string $kind, array $query): array
    {
        return app(DocumentContextResolver::class)->fromRequest(Request::create('/'.$kind, 'GET', $query), $kind, $this->context(), 1);
    }

    public function test_foreign_or_unusable_references_are_rejected_not_ignored(): void
    {
        foreach ([['sale', 'project_id'], ['purchase', 'project_id'], ['sale', 'purchase_order_id']] as [$kind, $key]) {
            try { $this->refs($kind, [$key => 999999]); $this->fail($kind.' '.$key); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(404, $error->getStatusCode()); }
        }
        try { $this->refs('purchase', ['exchange_return_id' => 1]); $this->fail('purchase exchange'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(404, $error->getStatusCode()); }
        try { $this->refs('sale', ['exchange_return_id' => 1]); $this->fail('exchange'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(503, $error->getStatusCode(), 'compliance is disabled in this fixture'); }
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->refs('purchase', ['purchase_order_id' => 'abc']);
    }

    public function test_a_purchase_order_reference_resolves_only_an_active_order_with_its_supplier_and_warehouse(): void
    {
        $product = $this->stock(100)->forceFill(['unit_id' => 1]); $product->save();
        $order = $this->postJson('/purchases', $this->purchaseData($product) + ['status' => 4, 'idempotency_key' => 'order-1'])->assertCreated()->json('data');
        $bill = $this->postJson('/purchases', $this->purchaseData($product) + ['idempotency_key' => 'bill-1'])->assertCreated()->json('data');
        $this->assertSame(['purchase_order_id' => $order['id'], 'party_id' => 1, 'warehouse_id' => 1],
            $this->refs('purchase', ['purchase_order_id' => $order['id']]));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->refs('purchase', ['purchase_order_id' => $bill['id']]);
    }

    public function test_a_stale_draft_reference_is_removed_with_a_warning_or_blocks_the_draft(): void
    {
        $stale = app(DocumentContextResolver::class)->revalidate(['purchase_order_id' => 424242, 'project_id' => 7], 'purchase', $this->context(), 1);
        $this->assertSame([], $stale['context']);
        $this->assertCount(1, $stale['blocking'], 'an order link changes what is posted, so it blocks the draft');
        $this->assertCount(1, $stale['warnings'], 'an optional project link is removed with a notice');
        $this->assertStringContainsString('has been removed from this draft', $stale['warnings'][0]);
    }
}
