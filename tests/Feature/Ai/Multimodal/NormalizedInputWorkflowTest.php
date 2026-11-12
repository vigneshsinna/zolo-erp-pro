<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Multimodal;

use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\FiscalYear;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class NormalizedInputWorkflowTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private int $branchId;
    private int $fyId;
    private User $admin;
    private Warehouse $warehouse;
    private Supplier $supplier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ai:rpm:nvidia');
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.provider', 'nvidia');
        config()->set('erp_ai.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');
        config()->set('ai.providers.nvidia.key', 'nvapi-workflow-key');
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        config()->set('ai_actions.foundation_enabled', true);

        $this->company = Company::create([
            'legal_name' => 'Workflow Bridge Co ' . uniqid(),
            'trade_name' => 'Workflow Bridge',
            'code' => 'WFB-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);
        $this->branchId = $this->company->branches()->create([
            'name' => 'Bridge Branch',
            'code' => 'BR-WFB-' . uniqid(),
            'is_active' => true,
        ])->id;
        $this->fyId = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ])->id;

        $this->admin = User::create([
            'name' => 'Bridge Admin',
            'email' => 'mm_wf_' . uniqid() . '@example.com',
            'phone' => '9833333333',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->company->users()->attach($this->admin->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'branch_id' => $this->branchId,
        ]);

        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'name' => 'Main Warehouse',
            'address' => 'Chennai',
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'name' => 'Anver Mills',
            'phone_number' => '9876500001',
            'email' => 'anver_' . uniqid() . '@example.com',
            'address' => 'Surat',
            'city' => 'Surat',
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'company_id' => $this->company->id,
            'unit_code' => 'PCS-' . uniqid(),
            'unit_name' => 'Pieces',
            'is_active' => true,
        ]);
        $cat = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Optical Frames',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Blue Fabric Frame',
            'code' => 'BFF-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $cat->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => '150.00',
            'price' => '250.00',
            'is_active' => true,
        ]);
        $this->product->company_id = $this->company->id;
        $this->product->save();

        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false, [
            'multimodal_enabled' => true,
            'daily_token_limit' => 250000,
        ]);
    }

    public function test_unconfirmed_extraction_and_unimplemented_purchase_bill_are_blocked_safely(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'purchase_bill',
                    'supplier_name' => 'Anver Mills',
                    'invoice_number' => 'PB-100',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 1500,
                    'tax_amount' => 0,
                    'total_amount' => 1500,
                    'lines' => [
                        ['description' => 'Blue Fabric Frame', 'quantity' => 10, 'unit_price' => 150, 'line_total' => 1500],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 80],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $up = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($jpg, 'bill.jpg', 'image/jpeg', null, true),
                'extract_now' => true,
            ])->assertStatus(201);

        $mediaId = $up->json('media.id');

        // Handoff blocked until user confirms
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$mediaId}/handoff")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'EXTRACTION_CONFIRMATION_REQUIRED');

        // Confirm extraction
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$mediaId}/confirm", ['expected_revision' => 1])
            ->assertStatus(200);

        // Direct purchase_bill posting is unimplemented -> refuses silently routing as PO
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$mediaId}/handoff")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CAPABILITY_UNAVAILABLE');

        // Now user explicitly converts into Purchase Order for Main Warehouse -> stages draft proposal safely!
        $poHandoff = $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$mediaId}/handoff", [
                'companion_text' => 'Create a purchase order from this for Main Warehouse',
            ])->assertStatus(200);

        $this->assertSame('proposal_created', $poHandoff->json('status'));
        $this->assertSame('purchase_order.create.v1', $poHandoff->json('action_key'));
        $this->assertNotEmpty($poHandoff->json('proposal.proposal_id'));

        $task = DB::table('ai_workflow_tasks')->where('id', $poHandoff->json('task_id'))->first();
        $this->assertNotNull($task);
        $prov = json_decode((string) $task->provenance_json, true);
        $this->assertSame($mediaId, $prov['media_id']);

        // Confirm zero actual Purchase rows written prior to explicit human proposal approval
        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());

        @unlink($jpg);
    }

    public function test_missing_product_enters_unified_clarification_card_and_posted_bill_mutation_blocks(): void
    {
        // Seed a posted purchase invoice
        Purchase::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'financial_year_id' => $this->fyId,
            'reference_no' => 'PR-POSTED-9',
            'supplier_invoice_no' => 'POSTED-999',
            'user_id' => $this->admin->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'item' => 1,
            'total_qty' => 1,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_cost' => 100,
            'grand_total' => 100,
            'paid_amount' => 0,
            'status' => 1,
            'payment_status' => 1,
            'posted_at' => now(),
        ]);

        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'purchase_order',
                    'supplier_name' => 'Anver Mills',
                    'invoice_number' => 'POSTED-999',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 500,
                    'tax_amount' => 0,
                    'total_amount' => 500,
                    'lines' => [
                        ['description' => 'Unregistered Titanium Frame', 'quantity' => 5, 'unit_price' => 100, 'line_total' => 500],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 180, 'completion_tokens' => 60],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $up = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($jpg, 'missing_prod.jpg', 'image/jpeg', null, true),
                'extract_now' => true,
            ])->assertStatus(201);
        $mediaId = $up->json('media.id');

        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$mediaId}/confirm", ['expected_revision' => 1])
            ->assertStatus(200);

        // Request trying to mutate already posted voucher is blocked cleanly
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$mediaId}/handoff", [
                'companion_text' => 'Edit posted invoice POSTED-999 and change qty',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NORMALIZED_INPUT_CONFLICT');

        // Normal PO draft with unknown product enters Phase 2.1 clarification cleanly!
        $clarifyRes = $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$mediaId}/handoff", [
                'companion_text' => 'Prepare purchase order for Main Warehouse',
            ])->assertStatus(200);

        $this->assertSame('needs_input', $clarifyRes->json('status'));
        $this->assertSame('items.0.product', $clarifyRes->json('clarification.missing_field'));
        $this->assertSame('items[line-0].product_id', $clarifyRes->json('clarification.required_inputs.0.field'));

        // Attach confirmed media to chat turn cleanly
        Http::fake([
            'integrate.api.nvidia.com/*' => function ($req) {
                $body = $req->data();
                $this->assertSame('nvidia/nemotron-3.5-lightning-30b-a3b', $body['model']);
                $sysPrompt = $body['messages'][0]['content'] ?? '';
                $this->assertStringContainsString('<untrusted_media_data>', $sysPrompt);
                $this->assertStringContainsString('Unregistered Titanium Frame', $sysPrompt);

                return Http::response([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Reviewed attachment noted.'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 20],
                ], 200);
            },
        ]);

        $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/chat', [
                'message' => 'What product name did we extract from this file?',
                'media_ids' => [$mediaId],
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        @unlink($jpg);
    }

    private function makeTempJpeg(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zolo_wf_' . uniqid() . '.jpg';
        $im = imagecreatetruecolor(120, 80);
        $bg = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, 120, 80, $bg);
        imagejpeg($im, $path, 85);
        imagedestroy($im);

        return $path;
    }
}
