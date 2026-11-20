<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpticalProfileIsolationAndProductBoxesTest extends TestCase
{
    public function test_tax_invoice_renders_rx_and_optical_charges_when_present(): void
    {
        $document = [
            'kind' => 'sale',
            'title' => 'Tax invoice',
            'number' => 'INV-2026-001',
            'date' => '2026-11-18',
            'company_name' => 'Lenz Optical Lab',
            'company_trade_name' => 'Lenz Optics',
            'company_address' => '123 Vision Way, Coimbatore',
            'company_state' => '33',
            'company_gstin' => '33AAAAA0000A1Z5',
            'party_name' => 'Vision Center Customer',
            'party_address' => '456 Eye Care Rd, Salem',
            'party_state' => '33',
            'party_gstin' => '33BBBBB1111B1Z2',
            'place_of_supply' => '33',
            'reverse_charge' => false,
            'currency' => 'INR',
            'quantity_scale' => 2,
            'total_tax' => 24.0,
            'shipping' => 0,
            'discount' => 0,
            'round_off' => 0,
            'grand_total' => 224.0,
            'paid_amount' => 224.0,
            'end_user_name' => 'John Doe',
            'end_user_phone' => '9876543210',
            'customer_order_no' => 'ORD-12345',
            'fitting_charge' => 150.00,
            'tinting_cost' => 50.00,
            'lines' => [
                [
                    'name' => 'Single Vision 1.56 HMC Lens',
                    'code' => 'SV-156',
                    'unit' => 'Pair',
                    'qty' => 1.0,
                    'price' => 200.0,
                    'tax_rate' => 12.0,
                    'taxable_value' => 200.0,
                    'cgst' => 12.0,
                    'sgst' => 12.0,
                    'igst' => 0,
                    'cess' => 0,
                    'hsn_sac' => '90014000',
                    'discount' => 0,
                    'sph' => '-1.50',
                    'cyl' => '-0.75',
                    'axis' => '90',
                    'addition' => '+1.50',
                    'dia' => '70',
                    'lr' => 'R',
                ]
            ],
            'print' => [],
        ];

        $html = view('backend.compliance.tax-invoice', [
            'document' => $document,
            'profile' => ['copies' => ['Original']],
        ])->render();

        // 1. Verify Patient and Order metadata rendered
        $this->assertStringContainsString('Patient / End User:</strong> John Doe', $html);
        $this->assertStringContainsString('Ph: 9876543210', $html);
        $this->assertStringContainsString('Customer Order No:</strong> ORD-12345', $html);

        // 2. Verify Optical Charges rendered in totals
        $this->assertStringContainsString('Fitting Charge', $html);
        $this->assertStringContainsString('Tinting Cost', $html);

        // 3. Verify Rx prescription badges rendered on the item line
        $this->assertStringContainsString('Eye:</strong> R', $html);
        $this->assertStringContainsString('SPH:</strong> -1.50', $html);
        $this->assertStringContainsString('CYL:</strong> -0.75', $html);
        $this->assertStringContainsString('AXIS:</strong> 90', $html);
        $this->assertStringContainsString('ADD:</strong> +1.50', $html);
        $this->assertStringContainsString('DIA:</strong> 70mm', $html);
    }

    public function test_tax_invoice_does_not_leak_rx_or_optical_charges_when_absent(): void
    {
        $document = [
            'kind' => 'sale',
            'title' => 'Tax invoice',
            'number' => 'INV-TEX-2026-99',
            'date' => '2026-11-18',
            'company_name' => 'ZTEX Textiles Pvt Ltd',
            'company_trade_name' => 'ZTEX',
            'company_address' => '789 Cotton Mills Rd, Tirupur',
            'company_state' => '33',
            'company_gstin' => '33CCCCC2222C1Z8',
            'party_name' => 'Global Fabrics Ltd',
            'party_address' => '101 Trade Park, Mumbai',
            'party_state' => '27',
            'party_gstin' => '27DDDDD3333D1Z4',
            'place_of_supply' => '27',
            'reverse_charge' => false,
            'currency' => 'INR',
            'quantity_scale' => 2,
            'total_tax' => 500.0,
            'shipping' => 100.0,
            'discount' => 0,
            'round_off' => 0,
            'grand_total' => 10600.0,
            'paid_amount' => 10600.0,
            'end_user_name' => null,
            'end_user_phone' => null,
            'customer_order_no' => null,
            'fitting_charge' => 0,
            'tinting_cost' => 0,
            'lines' => [
                [
                    'name' => 'Cotton Combed Yarn 40s',
                    'code' => 'YRN-40S',
                    'unit' => 'KGS',
                    'qty' => 50.0,
                    'price' => 200.0,
                    'tax_rate' => 5.0,
                    'taxable_value' => 10000.0,
                    'cgst' => 0,
                    'sgst' => 0,
                    'igst' => 500.0,
                    'cess' => 0,
                    'hsn_sac' => '5205',
                    'discount' => 0,
                    'sph' => null,
                    'cyl' => null,
                    'axis' => null,
                    'addition' => null,
                    'dia' => null,
                    'lr' => null,
                ]
            ],
            'print' => [],
        ];

        $html = view('backend.compliance.tax-invoice', [
            'document' => $document,
            'profile' => ['copies' => ['Original']],
        ])->render();

        // 1. Verify NO optical metadata leaks
        $this->assertStringNotContainsString('Patient / End User', $html);
        $this->assertStringNotContainsString('Customer Order No', $html);

        // 2. Verify NO optical charges leak
        $this->assertStringNotContainsString('Fitting Charge', $html);
        $this->assertStringNotContainsString('Tinting Cost', $html);

        // 3. Verify NO optical Rx badges or empty strips leak
        $this->assertStringNotContainsString('SPH:', $html);
        $this->assertStringNotContainsString('CYL:', $html);
        $this->assertStringNotContainsString('AXIS:', $html);
        $this->assertStringNotContainsString('ADD:', $html);
        $this->assertStringNotContainsString('DIA:', $html);
        $this->assertStringNotContainsString('Eye:', $html);
    }

    public function test_product_create_and_edit_initial_inline_styles_prevent_fouc(): void
    {
        $createBlade = file_get_contents(resource_path('views/backend/product/create.blade.php'));
        $editBlade = file_get_contents(resource_path('views/backend/product/edit.blade.php'));

        // 1. Verify create view has server-side display styles preventing FOUC
        $this->assertStringContainsString('id="diffPrice-section" style="display: {{ old(\'is_diffPrice\') ? \'block\' : \'none\' }};"', $createBlade);
        $this->assertStringContainsString('id="variant-section" style="display: {{ old(\'is_variant\') ? \'block\' : \'none\' }};"', $createBlade);
        $this->assertStringContainsString('id="promo-container" style="display: {{ old(\'promotion\') ? \'block\' : \'none\' }};"', $createBlade);
        $this->assertStringContainsString('id="combo" style="display: {{ old(\'type\') === \'combo\' ? \'block\' : \'none\' }};"', $createBlade);

        // 2. Verify edit view has server-side display styles preventing FOUC
        $this->assertStringContainsString('id="diffPrice-section" style="display: {{ (!empty($lims_product_data->is_diffPrice) || old(\'is_diffPrice\')) ? \'block\' : \'none\' }};"', $editBlade);
        $this->assertStringContainsString('id="variant-section" style="display: {{ (!empty($lims_product_data->is_variant) || old(\'is_variant\')) ? \'block\' : \'none\' }};"', $editBlade);
        $this->assertStringContainsString('id="promo-container" style="display: {{ (!empty($lims_product_data->promotion) || old(\'promotion\')) ? \'block\' : \'none\' }};"', $editBlade);
        $this->assertStringContainsString('id="combo" style="display: {{ ($lims_product_data->type === \'combo\' || old(\'type\') === \'combo\') ? \'block\' : \'none\' }};"', $editBlade);

        // 3. Verify fatal JavaScript syntax error `== 0` is permanently resolved
        $this->assertStringNotContainsString('{{$lims_product_data->is_addon}} == 0', $editBlade);
        $this->assertStringContainsString('var isAddon = {{ (int) ($lims_product_data->is_addon ?? 0) }};', $editBlade);
    }

    public function test_industry_profile_service_resolves_company_isolation_without_singleton_cache_pollution(): void
    {
        $request = request();
        $request->attributes->set('industry_profile_key_1', 'textile');
        $request->attributes->set('industry_profile_key_2', 'opticals');

        $context1 = new \App\Services\Platform\CompanyContext(1, 2026, 1);
        $context2 = new \App\Services\Platform\CompanyContext(2, 2026, 1);

        // Verify Company 1 resolves to textile and is NOT optical
        $profile1 = \App\Services\Industry\IndustryProfileService::currentProfileKey($context1);
        $this->assertEquals('textile', $profile1);
        $this->assertFalse(\App\Services\Industry\IndustryProfileService::isOptical($context1));

        // Verify Company 2 resolves to opticals and IS optical (no cache leakage from company 1)
        $profile2 = \App\Services\Industry\IndustryProfileService::currentProfileKey($context2);
        $this->assertEquals('opticals', $profile2);
        $this->assertTrue(\App\Services\Industry\IndustryProfileService::isOptical($context2));

        // Verify switching back to Company 1 still returns textile (no reverse cache pollution)
        $profile1Again = \App\Services\Industry\IndustryProfileService::currentProfileKey($context1);
        $this->assertEquals('textile', $profile1Again);
        $this->assertFalse(\App\Services\Industry\IndustryProfileService::isOptical($context1));
    }

    public function test_purchase_and_product_purchase_optical_accessors(): void
    {
        $pp = new \App\Models\ProductPurchase();
        $pp->stock_details_json = [
            'sph' => '-2.25',
            'cyl' => '-0.50',
            'axis' => '180',
            'addition' => '+2.00',
            'dia' => '65',
            'lr' => 'Pair',
            'base' => '4.00',
        ];

        $this->assertEquals('-2.25', $pp->sph);
        $this->assertEquals('-0.50', $pp->cyl);
        $this->assertEquals('180', $pp->axis);
        $this->assertEquals('+2.00', $pp->addition);
        $this->assertEquals('65', $pp->dia);
        $this->assertEquals('Pair', $pp->lr);
        $this->assertEquals('4.00', $pp->base);

        $purchase = new \App\Models\Purchase();
        $purchase->attributes_json = [
            'end_user_name' => 'Alice Patient',
            'end_user_phone' => '9944001122',
            'fitting_charge' => '120.50',
            'tinting_cost' => '75.00',
            'customer_order_no' => 'CUST-PO-99',
        ];

        $this->assertEquals('Alice Patient', $purchase->end_user_name);
        $this->assertEquals('9944001122', $purchase->end_user_phone);
        $this->assertEquals(120.50, $purchase->fitting_charge);
        $this->assertEquals(75.00, $purchase->tinting_cost);
        $this->assertEquals('CUST-PO-99', $purchase->customer_order_no);
    }

    public function test_purchase_index_blade_contains_optical_rx_strip_and_drawer(): void
    {
        $purchaseBlade = file_get_contents(resource_path('views/backend/purchase/index.blade.php'));

        // Verify Optical Rx strip in table row builder
        $this->assertStringContainsString('window.isOpticalProfile', $purchaseBlade);
        $this->assertStringContainsString('optical-rx-strip', $purchaseBlade);
        $this->assertStringContainsString('name="sph[]"', $purchaseBlade);
        $this->assertStringContainsString('name="cyl[]"', $purchaseBlade);
        $this->assertStringContainsString('name="axis[]"', $purchaseBlade);
        $this->assertStringContainsString('name="addition[]"', $purchaseBlade);
        $this->assertStringContainsString('name="lr[]"', $purchaseBlade);

        // Verify Optical & Warranty link and drawer pane
        $this->assertStringContainsString('id="btn-quick-optical"', $purchaseBlade);
        $this->assertStringContainsString('id="tab-optical"', $purchaseBlade);
        $this->assertStringContainsString('id="content-optical"', $purchaseBlade);
        $this->assertStringContainsString('id="drawer-end-user-name"', $purchaseBlade);
        $this->assertStringContainsString('id="drawer-fitting-charge"', $purchaseBlade);
        $this->assertStringContainsString('id="drawer-tinting-cost"', $purchaseBlade);
        $this->assertStringContainsString('id="hidden-fitting-charge"', $purchaseBlade);
    }
}
