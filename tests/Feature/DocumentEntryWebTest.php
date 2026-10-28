<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The real normal pages, rendered against the seeded development database inside a rolled-back transaction. */
class DocumentEntryWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commercial.enabled' => true]);
        putenv('ERP_OPTIONAL_ACTIVATION_READY=true');
        $_ENV['ERP_OPTIONAL_ACTIVATION_READY'] = 'true';
        \Illuminate\Support\Facades\Cache::flush();
        $this->actingAs(User::first());
    }

    public static function kinds(): array { return [['sale', '/sales', 'F2'], ['purchase', '/purchases', 'F12']]; }

    #[DataProvider('kinds')]
    public function test_normal_page_loads_the_registry_and_draft_tabs_without_separate_shortcut_menus(string $kind, string $path, string $key): void
    {
        $html = $this->get($path.'?new=1')->assertOk()->getContent();
        $this->assertStringContainsString('id="document-shortcut-data"', $html);
        $this->assertStringContainsString('js/document-shortcuts.js', $html);
        $this->assertStringContainsString('js/document-drafts.js', $html);
        $this->assertStringContainsString('js/document-workspace.js', $html);
        $this->assertStringContainsString('id="document-tab-add"', $html);
        $this->assertStringContainsString('title="New draft ('.$key.')"', $html);
        // The keys are accelerators only: no sidebar or toolbar entry duplicates them.
        $this->assertStringNotContainsString('data-document-shortcut=', $html);
        $this->assertStringNotContainsString('<kbd>'.$key.'</kbd>', $html);
        $this->assertStringNotContainsString('Fast Entry', $html);
        $this->assertStringNotContainsString('/commercial/'.$kind.'/entry', $html);
        $json = explode('</script>', explode('id="document-shortcut-data">', $html)[1])[0];
        $this->assertContains($kind, array_column(json_decode($json, true), 'id'));
    }

    #[DataProvider('kinds')]
    public function test_plain_page_is_the_register_form_page(string $kind, string $path): void
    {
        $this->get($path)->assertOk()->assertSee('id="'.$kind.'-entry-form"', false);
    }

    #[DataProvider('kinds')]
    public function test_the_page_script_defines_the_grid_before_the_form_scripts_read_it(string $kind, string $path): void
    {
        // With the Returns tab on, a nested partial's @push once moved command-center-form.js ahead of the page script,
        // so window.commandCenterGrid was undefined and every save failed on recalcTableSummary.
        config(['compliance.enabled' => true]);
        foreach (['', '?orders=1', '?returns=1'] as $query) {
            $html = $this->get($path.$query)->assertOk()->getContent();
            $grid = strpos($html, 'window.commandCenterGrid = {');
            $this->assertNotFalse($grid, $path.$query);
            $this->assertGreaterThan($grid, strpos($html, 'js/command-center-form.js'), $path.$query);
            $this->assertGreaterThan($grid, strpos($html, 'js/document-workspace.js'), $path.$query);
        }
    }

    public function test_purchase_orders_stay_out_of_the_bill_list(): void
    {
        config(['compliance.enabled' => true]);
        $this->assertSame(1, preg_match('/data-bill-id="(\d+)"/', $this->get('/purchases')->assertOk()->getContent(), $first));
        DB::table('purchases')->where('id', $first[1])->update(['status' => 4]);
        $this->assertStringNotContainsString('data-bill-id="'.$first[1].'"', $this->get('/purchases')->getContent());
    }

    public function test_a_purchase_order_prints_on_the_a4_bill_layout(): void
    {
        config(['compliance.enabled' => true]);
        $this->assertSame(1, preg_match('/data-bill-id="(\d+)"/', $this->get('/purchases')->assertOk()->getContent(), $first));
        DB::table('purchases')->where('id', $first[1])->update(['status' => 4, 'posted_at' => null, 'supplier_invoice_date' => '2026-11-05']);
        $html = $this->get('/compliance/documents/purchase/'.$first[1].'/print?format=a4')->assertOk()->getContent();
        $this->assertStringContainsString('Purchase order', $html);
        $this->assertStringContainsString('PO No.', $html);
        $this->assertStringContainsString('05-11-2026', $html, 'the expected delivery date prints');
        $this->assertStringNotContainsString('Bill No.', $html);
    }

    #[DataProvider('kinds')]
    public function test_linked_references_must_exist_in_the_trusted_company_context(string $kind, string $path): void
    {
        $this->get($path.'?new=1&project_id=999999')->assertStatus(404);
        $this->get($path.'?new=1&purchase_order_id=999999')->assertStatus(404);
        config(['compliance.enabled' => true]);
        $this->get($path.'?new=1&exchange_return_id=999999')->assertStatus(404);
    }

    public function test_challan_grn_and_quotation_pages_open_in_their_new_state_with_draft_tabs(): void
    {
        $pages = ['/delivery-challans?new=1' => 'documents/dc', '/goods-received-notes?new=1' => 'documents/grn', '/quotations/create' => 'documents/quotation'];
        foreach ($pages as $path => $drafts) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('id="document-tabs"', $html, $path);
            $this->assertStringContainsString('js/document-drafts.js', $html, $path);
            $this->assertStringContainsString(str_replace('/', '\/', $drafts), $html, $path);
        }
    }

    public function test_old_return_pages_open_the_returns_tab_of_their_workspace(): void
    {
        $this->get('/return-sale?new=1')->assertRedirect(route('sales.index', ['returns' => 1]));
        $this->get('/return-purchase?new=1')->assertRedirect(route('purchases.index', ['returns' => 1]));
        $this->get('/return-sale/create?reference_no=NO-SUCH-BILL')->assertRedirect(route('sales.index', ['returns' => 1]));
        $this->get('/sales/create')->assertRedirect(route('sales.index', ['new' => 1]));
        $this->get('/purchases/create')->assertRedirect(route('purchases.index', ['new' => 1]));
    }
}
