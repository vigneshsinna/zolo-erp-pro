<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
    public function test_normal_page_offers_the_new_bill_action_and_loads_the_registry(string $kind, string $path, string $key): void
    {
        $html = $this->get($path.'?new=1')->assertOk()->getContent();
        $this->assertStringContainsString('id="document-shortcut-data"', $html);
        $this->assertStringContainsString('js/document-shortcuts.js', $html);
        $this->assertStringContainsString('js/document-workspace.js', $html);
        $this->assertStringContainsString('data-document-shortcut="'.$kind.'"', $html);
        $this->assertStringContainsString('<kbd>'.$key.'</kbd>', $html);
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
    public function test_linked_references_must_exist_in_the_trusted_company_context(string $kind, string $path): void
    {
        $this->get($path.'?new=1&project_id=999999')->assertStatus(404);
        $this->get($path.'?new=1&purchase_order_id=999999')->assertStatus(404);
        config(['compliance.enabled' => true]);
        $this->get($path.'?new=1&exchange_return_id=999999')->assertStatus(404);
    }

    public function test_challan_grn_return_and_quotation_pages_open_in_their_new_state(): void
    {
        foreach (['/delivery-challans?new=1', '/goods-received-notes?new=1', '/return-sale?new=1', '/return-purchase?new=1', '/quotations/create'] as $path) {
            $this->get($path)->assertOk();
        }
    }
}
