<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ReturnsDocumentTestCase;

/** Browser fixture export is opt-in and always isolated beneath scratch. */
class ComplianceBrowserFixtureTest extends ReturnsDocumentTestCase
{
    public function test_pages_render_and_optionally_export_disposable_browser_fixture(): void
    {
        $sale = $this->gstSale();
        $this->postedReturn($sale);
        $this->get('/compliance/returns')->assertRedirect(route('sales.index', ['returns' => 1]));
        $this->get('/compliance/sale/'.$sale->id.'/return')->assertRedirect(route('sales.index', ['returns' => 1, 'bill' => $sale->id]));
        foreach (['/compliance/setup', '/compliance/gst/report',
            '/compliance/documents/sale/'.$sale->id,
            '/compliance/documents/sale/'.$sale->id.'/print?format=a4', '/compliance/documents/sale/'.$sale->id.'/print?format=thermal'] as $url) {
            $this->get($url)->assertOk();
        }
        if (getenv('ERP_EXPORT_DOCUMENT_LAYOUT') === '1') {
            if (!is_dir(base_path('scratch'))) mkdir(base_path('scratch'), 0777, true);
            file_put_contents(base_path('scratch/document-compliance-return-fixture.html'),
                $this->get('/compliance/sale/'.$sale->id.'/return')->assertOk()->getContent());
        }
        if (getenv('ERP_EXPORT_COMPLIANCE_BROWSER') === '1') {
            $this->assertSame('sqlite', DB::connection()->getDriverName());
            $directory = base_path('scratch'); if (!is_dir($directory)) mkdir($directory, 0777, true);
            $path = $directory.'/compliance-browser-'.bin2hex(random_bytes(6)).'.sqlite';
            DB::statement('VACUUM INTO '.DB::connection()->getPdo()->quote($path));
            file_put_contents($directory.'/compliance-browser-path.txt', $path);
        }
    }
}
