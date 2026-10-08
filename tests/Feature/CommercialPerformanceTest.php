<?php

namespace Tests\Feature;

use App\Services\Commercial\PartyQueryService;
use App\Services\Commercial\ProductQueryService;
use App\Services\Commercial\SaleApplicationService;
use App\Services\Commercial\SaleCommand;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommercialTestCase;

/** Opt-in timing proof; fixture seeding is excluded from measured samples. */
class CommercialPerformanceTest extends CommercialTestCase
{
    public function test_50000_item_lookup_and_twenty_line_post_budgets(): void
    {
        if (getenv('ERP_COMMERCIAL_PERF') !== '1') $this->markTestSkipped('Set ERP_COMMERCIAL_PERF=1 for the large fixture timing proof.');
        for ($start = 1; $start <= 50000; $start += 500) {
            $rows = $parties = [];
            for ($i = $start; $i < $start + 500; $i++) {
                $rows[] = ['company_id' => $this->company->id, 'name' => 'Perf item '.sprintf('%05d', $i), 'code' => 'PERF'.sprintf('%05d', $i),
                    'type' => 'standard', 'price' => 10, 'cost' => 5, 'qty' => 0, 'unit_id' => 1, 'is_active' => true];
                $parties[] = ['company_id' => $this->company->id, 'name' => 'Perf party '.sprintf('%05d', $i), 'city' => 'City'.sprintf('%05d', $i), 'is_active' => true];
            }
            DB::table('products')->insert($rows); DB::table('customers')->insert($parties);
        }
        $lines = [];
        for ($i = 0; $i < 20; $i++) $lines[] = ['product_id' => $this->stock(100)->id, 'qty' => 1, 'net_unit_price' => 10];
        $time = function (callable $call): float { $start = hrtime(true); $call(); return (hrtime(true) - $start) / 1000000; };
        $this->withoutMiddleware(\App\Http\Middleware\RequireCapability::class);
        $products = $parties = $postings = $screens = [];
        for ($i = 0; $i < 30; $i++) {
            $products[] = $time(fn () => app(ProductQueryService::class)->search('PERF499', $this->context()));
            $parties[] = $time(fn () => app(PartyQueryService::class)->search('sale', 'City499', $this->context()));
            $postings[] = $time(fn () => app(SaleApplicationService::class)->create(new SaleCommand(
                $this->saleData(\App\Models\Product::findOrFail($lines[0]['product_id']), ['items' => $lines]), 'perf-'.$i, 1, $this->context())));
        }
        // The draft-tab list is the one request every Sales page load adds; measure it separately from service calls.
        $this->getJson('/commercial/sale/drafts')->assertOk();
        for ($i = 0; $i < 30; $i++) $screens[] = $time(fn () => $this->getJson('/commercial/sale/drafts')->assertOk());
        $p95 = function (array $samples): float { sort($samples); return round($samples[(int) ceil(count($samples) * .95) - 1], 2); };
        $metrics = ['driver' => DB::connection()->getDriverName(), 'items' => 50000, 'parties' => 50000, 'samples' => 30,
            'product_search_p95_ms' => $p95($products), 'party_search_p95_ms' => $p95($parties), 'twenty_line_post_p95_ms' => $p95($postings), 'warm_draft_list_p95_ms' => $p95($screens)];
        fwrite(STDOUT, "\nCommercial timing: ".json_encode($metrics)."\n");
        $this->assertLessThanOrEqual(300, $metrics['product_search_p95_ms']);
        $this->assertLessThanOrEqual(300, $metrics['party_search_p95_ms']);
        $this->assertLessThanOrEqual(1000, $metrics['twenty_line_post_p95_ms']);
        $this->assertLessThanOrEqual(1500, $metrics['warm_draft_list_p95_ms']);
        $this->assertSame(30, DB::table('sales')->count());
    }
}
