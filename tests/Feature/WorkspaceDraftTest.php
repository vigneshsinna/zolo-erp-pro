<?php

namespace Tests\Feature;

use App\Services\Commercial\CommercialDraftService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommercialTestCase;

/** Server-side draft tabs: an unfinished workspace, never a posted document. */
class WorkspaceDraftTest extends CommercialTestCase
{
    private function save(string $kind = 'sale', array $fields = [], ?int $id = null, int $version = 0, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/commercial/'.$kind.'/drafts', ['payload' => $this->snapshot($kind, $fields), 'id' => $id, 'version' => $version] + $extra);
    }

    private function insertDraft(array $attributes): int
    {
        return DB::table('sale_drafts')->insertGetId($attributes + ['company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'financial_year_id' => $this->year->id, 'user_id' => 1, 'kind' => 'sale', 'version' => 1,
            'payload_json' => json_encode($this->snapshot('sale')), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_create_update_list_get_and_delete_keep_navigation_metadata_out_of_the_payload(): void
    {
        $created = $this->save('sale', ['customer_id' => '1'], null, 0, ['party_id' => 1, 'party_name' => 'ABC Traders'])->assertOk();
        $created->assertJsonPath('data.title', 'Draft 1')->assertJsonPath('data.version', 1)->assertJsonPath('data.party_name_snapshot', 'ABC Traders');
        $id = $created->json('data.id');

        $updated = $this->save('sale', ['customer_id' => '2'], $id, 1, ['party_id' => 2, 'party_name' => 'XYZ Stores'])->assertOk();
        $updated->assertJsonPath('data.version', 2)->assertJsonPath('data.title', 'Draft 1')->assertJsonPath('data.party_name_snapshot', 'XYZ Stores');

        $list = $this->getJson('/commercial/sale/drafts')->assertOk();
        $list->assertJsonPath('open', 1)->assertJsonPath('limit', 10)->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('payload_json', $list->json('data.0'), 'the tab strip never decodes the payload');

        $loaded = $this->getJson('/commercial/sale/drafts/'.$id)->assertOk();
        $loaded->assertJsonPath('data.payload.form.fields.customer_id', '2')->assertJsonPath('data.legacy', false);

        $this->deleteJson('/commercial/sale/drafts/'.$id)->assertOk();
        $this->getJson('/commercial/sale/drafts/'.$id)->assertNotFound();
        $this->assertSame(0, DB::table('sales')->count() + DB::table('stock_movements')->count(), 'a draft has no business effect');
    }

    public function test_titles_are_sequential_and_a_freed_number_is_reused(): void
    {
        $first = $this->save()->json('data.id');
        $second = $this->save()->assertJsonPath('data.title', 'Draft 2')->json('data.id');
        $this->deleteJson('/commercial/sale/drafts/'.$first)->assertOk();
        $this->save()->assertJsonPath('data.title', 'Draft 1');
        $this->assertNotNull($second);
    }

    public function test_drafts_are_isolated_by_user_company_branch_year_and_kind(): void
    {
        $other = ['payload_json' => json_encode($this->snapshot('sale', ['note' => 'private']))];
        $otherUser = $this->insertDraft($other + ['user_id' => 2]);
        $otherBranch = $this->insertDraft($other + ['branch_id' => $this->branch->id + 100]);
        $otherYear = $this->insertDraft($other + ['financial_year_id' => $this->year->id + 100]);
        $otherCompany = $this->insertDraft($other + ['company_id' => $this->other->id]);
        $purchase = $this->insertDraft($other + ['kind' => 'purchase']);
        $mine = $this->save()->json('data.id');

        $this->assertSame([$mine], array_column($this->getJson('/commercial/sale/drafts')->json('data'), 'id'), 'the tab strip shows only this user, branch, year and kind');
        foreach ([$otherUser, $otherBranch, $otherYear, $otherCompany, $purchase] as $foreign) {
            $this->getJson('/commercial/sale/drafts/'.$foreign)->assertNotFound();
            $this->deleteJson('/commercial/sale/drafts/'.$foreign)->assertNotFound();
            $this->save('sale', ['note' => 'overwrite'], $foreign, 1)->assertNotFound();
        }
        $this->assertSame(6, DB::table('sale_drafts')->count());
        $this->assertEquals($this->snapshot('sale', ['note' => 'private']), json_decode(DB::table('sale_drafts')->where('id', $otherUser)->value('payload_json'), true));
    }

    public function test_saving_requires_the_add_permission_for_that_kind(): void
    {
        Schema::create('permissions', function (Blueprint $t) { $t->increments('id'); $t->string('name'); });
        Schema::create('role_has_permissions', function (Blueprint $t) { $t->unsignedInteger('role_id'); $t->unsignedInteger('permission_id'); });
        DB::table('permissions')->insert(['id' => 1, 'name' => 'purchases-add']);
        DB::table('role_has_permissions')->insert(['role_id' => 4, 'permission_id' => 1]);
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', 1)->update(['role_id_override' => 4]);
        $this->save('sale')->assertForbidden();
        $this->getJson('/commercial/sale/drafts')->assertForbidden();
        $this->save('purchase')->assertOk();
        $this->assertSame(1, DB::table('sale_drafts')->count());
    }

    public function test_a_stale_version_is_a_409_that_names_the_current_version_and_never_overwrites(): void
    {
        $id = $this->save('sale', ['note' => 'one'])->json('data.id');
        $this->save('sale', ['note' => 'two'], $id, 1)->assertOk();
        $conflict = $this->save('sale', ['note' => 'stale window'], $id, 1)->assertStatus(409);
        $conflict->assertJsonPath('code', 'draft_conflict')->assertJsonPath('current.version', 2);
        $this->assertSame('two', json_decode(DB::table('sale_drafts')->where('id', $id)->value('payload_json'), true)['form']['fields']['note']);
    }

    public function test_the_ten_draft_limit_is_per_user_company_and_kind_across_branches_and_never_evicts(): void
    {
        for ($i = 0; $i < 4; $i++) $this->insertDraft(['branch_id' => $this->branch->id + 1 + $i]);
        for ($i = 0; $i < 6; $i++) $this->save()->assertOk();
        $this->assertSame(10, DB::table('sale_drafts')->where('user_id', 1)->where('kind', 'sale')->count());
        $blocked = $this->save()->assertStatus(422);
        $this->assertStringContainsString('You already have 10 open Sales drafts', $blocked->json('errors.draft.0'));
        $this->assertSame(10, DB::table('sale_drafts')->where('kind', 'sale')->where('user_id', 1)->count(), 'nothing is auto-deleted');
        $this->save('purchase')->assertOk();
        $this->insertDraft(['user_id' => 2]);
        $this->insertDraft(['company_id' => $this->other->id]);
        $first = DB::table('sale_drafts')->where('user_id', 1)->where('kind', 'sale')->where('branch_id', $this->branch->id)->orderBy('id')->value('id');
        $this->save('sale', ['note' => 'edit existing at the limit'], $first, 1)->assertOk();
    }

    public function test_prune_measures_age_from_the_last_edit_not_from_creation(): void
    {
        $stale = $this->insertDraft(['created_at' => now()->subDays(60), 'updated_at' => now()->subDays(31)]);
        $editedRecently = $this->insertDraft(['created_at' => now()->subDays(60), 'updated_at' => now()->subDays(29)]);
        $this->assertSame(1, app(CommercialDraftService::class)->pruneExpired());
        $this->assertDatabaseMissing('sale_drafts', ['id' => $stale]);
        $this->assertDatabaseHas('sale_drafts', ['id' => $editedRecently]);

        $lazy = $this->insertDraft(['updated_at' => now()->subDays(45)]);
        $this->getJson('/commercial/sale/drafts')->assertOk()->assertJsonPath('open', 1);
        $this->assertDatabaseMissing('sale_drafts', ['id' => $lazy]);
        $this->artisan('commercial:prune-drafts')->assertExitCode(0);
    }

    public function test_two_megabytes_are_accepted_and_more_is_rejected_whole_without_truncation(): void
    {
        $this->save('sale', ['note' => str_repeat('a', 1900000)])->assertOk();
        $this->assertSame(1, DB::table('sale_drafts')->count());
        $rejected = $this->save('sale', ['note' => str_repeat('b', 2200000)])->assertStatus(422);
        $this->assertStringContainsString('too large to save as a draft', $rejected->json('errors.draft.0'));
        $this->assertSame(1, DB::table('sale_drafts')->count(), 'no partial draft is stored');
    }

    public function test_only_the_versioned_snapshot_shape_is_accepted(): void
    {
        $this->postJson('/commercial/sale/drafts', ['payload' => ['note' => 'free-form'], 'version' => 0])->assertStatus(422);
        $this->postJson('/commercial/sale/drafts', ['payload' => $this->snapshot('purchase'), 'version' => 0])->assertStatus(422);
        $this->assertSame(0, DB::table('sale_drafts')->count());
    }

    public function test_a_failed_post_keeps_the_draft_and_a_successful_post_removes_it(): void
    {
        $product = $this->stock(100)->forceFill(['unit_id' => 1]); $product->save();
        $id = $this->save('purchase', ['note' => 'to post'])->json('data.id');
        $data = $this->purchaseData($product) + ['draft_id' => $id];
        $bad = $data + ['idempotency_key' => 'draft-bad'];
        $bad['supplier_id'] = 999999;
        $this->postJson('/purchases', $bad)->assertStatus(422);
        $this->assertDatabaseHas('sale_drafts', ['id' => $id]);
        $this->assertSame(0, DB::table('purchases')->count());

        $this->postJson('/purchases', $data + ['idempotency_key' => 'draft-good'])->assertCreated();
        $this->assertDatabaseMissing('sale_drafts', ['id' => $id]);
        $this->assertSame(1, DB::table('purchases')->count());
    }

    public function test_a_stale_order_reference_is_revalidated_when_the_draft_loads_and_blocks_posting(): void
    {
        $product = $this->stock(100)->forceFill(['unit_id' => 1]); $product->save();
        $order = $this->postJson('/purchases', $this->purchaseData($product) + ['status' => 4, 'idempotency_key' => 'order-for-draft'])->assertCreated()->json('data');
        $payload = $this->snapshot('purchase', [], [], ['purchase_order_id' => $order['id']]);
        $id = $this->postJson('/commercial/purchase/drafts', ['payload' => $payload, 'version' => 0])->assertOk()->json('data.id');
        $this->getJson('/commercial/purchase/drafts/'.$id)->assertOk()->assertJsonPath('data.blocking', [])
            ->assertJsonPath('data.payload.form.context.purchase_order_id', $order['id']);

        DB::table('purchases')->where('id', $order['id'])->update(['reversed_at' => now()]);
        $stale = $this->getJson('/commercial/purchase/drafts/'.$id)->assertOk();
        $this->assertCount(1, $stale->json('data.blocking'));
        $this->assertSame([], $stale->json('data.payload.form.context'), 'a stale reference is never silently replaced');

        $post = $this->purchaseData($product) + ['draft_id' => $id, 'idempotency_key' => 'post-with-stale-order'];
        $this->postJson('/purchases', $post)->assertStatus(422);
        $this->assertDatabaseHas('sale_drafts', ['id' => $id]);
        $this->assertSame(1, DB::table('purchases')->count(), 'only the order exists; the blocked draft posted nothing');
        $this->postJson('/commercial/purchase/drafts', ['payload' => $payload, 'version' => 0])->assertStatus(422);
    }

    public function test_an_unavailable_optional_project_link_is_removed_with_a_notice(): void
    {
        $payload = $this->snapshot('sale', [], [], ['project_id' => 77]);
        $response = $this->postJson('/commercial/sale/drafts', ['payload' => $payload, 'version' => 0])->assertOk();
        $this->assertStringContainsString('has been removed from this draft', $response->json('warnings.0'));
        $id = $response->json('data.id');
        $this->assertStringNotContainsString('project_id', DB::table('sale_drafts')->where('id', $id)->value('payload_json'));
    }
}
