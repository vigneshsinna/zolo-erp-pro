<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NumbersLegacyDocuments;
use App\Models\Agent;
use App\Models\Area;
use App\Models\BillSundry;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\DeliveryChallanItem;
use App\Models\DocumentSeries;
use App\Models\GeneralSetting;
use App\Models\Product;
use App\Models\SaleType;
use App\Models\StandardRemark;
use App\Models\Tax;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeliveryChallanController extends Controller
{
    use NumbersLegacyDocuments, \App\Http\Controllers\Concerns\ValidatesMaterialDocuments, \App\Http\Controllers\Concerns\PostsMaterialDocuments;

    public function index(Request $request)
    {
        $role = DB::table('roles')->find(Auth::user()->role_id ?? 1);
        if ($role && $role->id > 2 && method_exists(Auth::user(), 'hasPermissionTo') && !Auth::user()->hasPermissionTo('delivery-challans-index')) {
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
        }

        $general_setting = DB::table('general_settings')->latest()->first();
        $currency = $general_setting && $general_setting->currency ? Currency::find($general_setting->currency) : null;

        $recent_challans = DeliveryChallan::with(['customer:id,name,phone_number', 'warehouse:id,name', 'saleType:id,name', 'agent:id,name'])
            ->latest('id')
            ->limit(50)
            ->get();

        $lims_customer_list = Customer::where('is_active', true)->select('id', 'name', 'phone_number', 'city', 'address')->get();
        $lims_warehouse_list = Warehouse::where('is_active', true)->select('id', 'name')->get();
        $lims_tax_list = Tax::where('is_active', true)->get();

        $saleTypes = SaleType::where('is_active', true)->orderBy('name')->get();
        $agents = Agent::where('is_active', true)->orderBy('name')->get();
        $areas = Area::where('is_active', true)->orderBy('name')->get();
        $billSundries = BillSundry::where('is_active', true)->orderBy('name')->get();
        $standardRemarks = StandardRemark::where('is_active', true)->orderBy('title')->get();
        $documentSeries = DocumentSeries::where('document_type', 'delivery_challan')->orderBy('code')->get();

        $lims_product_list_without_variant = Product::ActiveStandard()
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->select('products.id', 'products.name', 'products.code', 'products.price', 'products.cost', 'products.tax_id', 'products.unit_id', 'products.is_variant', 'units.unit_name', 'units.unit_code')
            ->whereNull('products.is_variant')
            ->get();

        $lims_product_list_with_variant = Product::ActiveStandard()
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->select('products.id', 'products.name', 'products.code', 'products.price', 'products.cost', 'products.tax_id', 'products.unit_id', 'products.is_variant', 'units.unit_name', 'units.unit_code')
            ->whereNotNull('products.is_variant')
            ->get();

        $materialVariants = $this->materialVariants($lims_product_list_with_variant->where('is_variant', true)->pluck('id')->all());
        return view('backend.delivery_challan.index', compact(
            'materialVariants',
            'recent_challans',
            'lims_customer_list',
            'lims_warehouse_list',
            'lims_tax_list',
            'saleTypes',
            'agents',
            'areas',
            'billSundries',
            'standardRemarks',
            'documentSeries',
            'lims_product_list_without_variant',
            'lims_product_list_with_variant',
            'currency',
            'general_setting'
        ));
    }

    public function store(Request $request)
    {
        $this->validateMaterialDocument($request, false);

        DB::beginTransaction();
        try {
            $companyId = request()->attributes->get(\App\Services\Platform\CompanyContext::class)?->companyId ?? abort(409, 'Choose a company first.');
            $reservation = null;

            // Generate atomic document number or use provided
            $challanNo = $request->input('challan_no');
            if (empty($challanNo)) {
                try {
                    $reservation = $this->reserveNumber('delivery_challan', $request->input('challan_date'));
                    $challanNo = $reservation->formatted_number;
                } catch (\Throwable $e) {
                    $challanNo = 'DC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
                }
            }

            $challan = DeliveryChallan::create([
                'company_id' => $companyId,
                'challan_no' => $challanNo,
                'challan_date' => $request->input('challan_date'),
                'customer_id' => (int)$request->input('customer_id'),
                'warehouse_id' => (int)$request->input('warehouse_id'),
                'sale_type_id' => $request->input('sale_type_id') ? (int)$request->input('sale_type_id') : null,
                'agent_id' => $request->input('agent_id') ? (int)$request->input('agent_id') : null,
                'area_id' => $request->input('area_id') ? (int)$request->input('area_id') : null,
                'transport_name' => $request->input('transport_name'),
                'lr_no' => $request->input('lr_no'),
                'lr_date' => $request->input('lr_date') ?: null,
                'bale_no' => $request->input('bale_no'),
                'no_of_bales' => $request->input('no_of_bales') ? (int)$request->input('no_of_bales') : null,
                'station_to' => $request->input('station_to'),
                'lot_number' => $request->input('lot_number') ?: ($request->input('bale_no') ?: null),
                'total_qty' => (float)$request->input('total_qty', 0),
                'total_amount' => (float)$request->input('total_amount', 0),
                'total_tax' => (float)$request->input('total_tax', 0),
                'grand_total' => (float)$request->input('grand_total', 0),
                'sundries_json' => $request->input('sundries_json') ? (is_array($request->input('sundries_json')) ? $request->input('sundries_json') : json_decode($request->input('sundries_json'), true)) : null,
                'remarks' => $request->input('remarks'),
                'purpose' => $request->input('purpose') ?: 'approval',
                'status' => 'pending',
                'created_by' => Auth::id() ?? 1,
            ]);

            if ($reservation) {
                $this->assignNumber($reservation, $challan);
            }

            // Save items
            $productIds = $request->input('product_id', []);
            $qtys = $request->input('qty', []);
            $rates = $request->input('rate', []);
            $amounts = $request->input('amount', []);
            $taxRates = $request->input('tax_rate', []);
            $taxAmounts = $request->input('tax_amount', []);
            $totals = $request->input('total', []);
            $unitIds = $request->input('unit_id', []);
            $itemRemarks = $request->input('item_remarks', []);
            $batchNos = $request->input('batch_no', []);

            foreach ($productIds as $idx => $prodId) {
                if (empty($prodId)) continue;
                DeliveryChallanItem::create([
                    'company_id' => $companyId,
                    'delivery_challan_id' => $challan->id,
                    'product_id' => (int)$prodId,
                    'unit_id' => !empty($unitIds[$idx]) ? (int)$unitIds[$idx] : 1,
                    'qty' => (float)($qtys[$idx] ?? 1),
                    'rate' => (float)($rates[$idx] ?? 0),
                    'amount' => (float)($amounts[$idx] ?? 0),
                    'tax_rate' => (float)($taxRates[$idx] ?? 0),
                    'tax_amount' => (float)($taxAmounts[$idx] ?? 0),
                    'total' => (float)($totals[$idx] ?? 0),
                    'remarks' => $itemRemarks[$idx] ?? null,
                ] + $this->materialIdentityAttributes($request, $idx, false));
            }

            $this->postMaterial($challan);

            DB::commit();
            app(\App\Services\Commercial\CommercialDraftService::class)->forgetPosted('dc', $request->integer('draft_id'), (int) Auth::id());

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Delivery Challan created successfully: ' . $challan->challan_no,
                    'challan' => $challan,
                ], 201);
            }

            return redirect()->route('delivery-challans.index')->with('message', 'Delivery Challan created successfully: ' . $challan->challan_no);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return redirect()->back()->with('not_permitted', 'Error creating Delivery Challan: ' . $e->getMessage())->withInput();
        }
    }

    public function show($id)
    {
        $challan = DeliveryChallan::with(['items.product', 'items.unit', 'customer', 'warehouse', 'saleType', 'agent', 'area'])->findOrFail($id);

        if (request()->wantsJson() || request()->ajax()) {
            $open = app(\App\Services\Commercial\MaterialDocumentService::class)->openQuantities($challan);
            return response()->json([
                'success' => true,
                'challan' => $challan,
                'history' => $this->materialHistory($challan),
                'items' => $challan->items->map(function ($item) use ($open) {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product ? $item->product->name : 'Item #' . $item->product_id,
                        'product_code' => $item->product ? $item->product->code : '',
                        'unit_id' => $item->unit_id,
                        'unit' => $item->unit ? ($item->unit->unit_code ?? $item->unit->unit_name) : 'Unit',
                        'qty' => (float)$item->qty,
                        'rate' => (float)$item->rate,
                        'amount' => (float)$item->amount,
                        'tax_rate' => (float)$item->tax_rate,
                        'tax_amount' => (float)$item->tax_amount,
                        'total' => (float)$item->total,
                        'remarks' => $item->remarks,
                    ] + $this->materialLine($item, $open);
                }),
            ]);
        }

        return redirect()->route('delivery-challans.index', ['edit' => $challan->id]);
    }

    public function update(Request $request, $id)
    {
        $challan = DeliveryChallan::findOrFail($id);
        abort_unless($challan->status === 'pending', 422, 'Converted or cancelled documents cannot be changed.');

        $this->validateMaterialDocument($request, false);

        DB::beginTransaction();
        try {
            $challan = DeliveryChallan::lockForUpdate()->findOrFail($id);
            abort_unless($challan->status === 'pending', 422, 'Converted or cancelled documents cannot be changed.');
            $companyId = request()->attributes->get(\App\Services\Platform\CompanyContext::class)?->companyId ?? abort(409, 'Choose a company first.');

            $challan->update([
                'challan_date' => $request->input('challan_date'),
                'customer_id' => (int)$request->input('customer_id'),
                'warehouse_id' => (int)$request->input('warehouse_id'),
                'sale_type_id' => $request->input('sale_type_id') ? (int)$request->input('sale_type_id') : null,
                'agent_id' => $request->input('agent_id') ? (int)$request->input('agent_id') : null,
                'area_id' => $request->input('area_id') ? (int)$request->input('area_id') : null,
                'transport_name' => $request->input('transport_name'),
                'lr_no' => $request->input('lr_no'),
                'lr_date' => $request->input('lr_date') ?: null,
                'bale_no' => $request->input('bale_no'),
                'no_of_bales' => $request->input('no_of_bales') ? (int)$request->input('no_of_bales') : null,
                'station_to' => $request->input('station_to'),
                'lot_number' => $request->input('lot_number') ?: ($request->input('bale_no') ?: null),
                'total_qty' => (float)$request->input('total_qty', 0),
                'total_amount' => (float)$request->input('total_amount', 0),
                'total_tax' => (float)$request->input('total_tax', 0),
                'grand_total' => (float)$request->input('grand_total', 0),
                'sundries_json' => $request->input('sundries_json') ? (is_array($request->input('sundries_json')) ? $request->input('sundries_json') : json_decode($request->input('sundries_json'), true)) : null,
                'remarks' => $request->input('remarks'),
                'purpose' => $request->input('purpose') ?: 'approval',
            ]);

            // Replace items
            $this->unpostMaterial($challan, 'Edited before billing');
            DeliveryChallanItem::where('delivery_challan_id', $challan->id)->delete();

            $productIds = $request->input('product_id', []);
            $qtys = $request->input('qty', []);
            $rates = $request->input('rate', []);
            $amounts = $request->input('amount', []);
            $taxRates = $request->input('tax_rate', []);
            $taxAmounts = $request->input('tax_amount', []);
            $totals = $request->input('total', []);
            $unitIds = $request->input('unit_id', []);
            $itemRemarks = $request->input('item_remarks', []);
            $batchNos = $request->input('batch_no', []);

            foreach ($productIds as $idx => $prodId) {
                if (empty($prodId)) continue;
                DeliveryChallanItem::create([
                    'company_id' => $companyId,
                    'delivery_challan_id' => $challan->id,
                    'product_id' => (int)$prodId,
                    'unit_id' => !empty($unitIds[$idx]) ? (int)$unitIds[$idx] : 1,
                    'qty' => (float)($qtys[$idx] ?? 1),
                    'rate' => (float)($rates[$idx] ?? 0),
                    'amount' => (float)($amounts[$idx] ?? 0),
                    'tax_rate' => (float)($taxRates[$idx] ?? 0),
                    'tax_amount' => (float)($taxAmounts[$idx] ?? 0),
                    'total' => (float)($totals[$idx] ?? 0),
                    'remarks' => $itemRemarks[$idx] ?? null,
                ] + $this->materialIdentityAttributes($request, $idx, false));
            }

            $this->postMaterial($challan);

            DB::commit();
            app(\App\Services\Commercial\CommercialDraftService::class)->forgetPosted('dc', $request->integer('draft_id'), (int) Auth::id());

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Delivery Challan updated successfully: ' . $challan->challan_no,
                    'challan' => $challan,
                ]);
            }

            return redirect()->route('delivery-challans.index')->with('message', 'Delivery Challan updated: ' . $challan->challan_no);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return redirect()->back()->with('not_permitted', 'Error updating Delivery Challan: ' . $e->getMessage())->withInput();
        }
    }

    public function returnGoods(Request $request, $id)
    {
        return $this->returnMaterial($request, DeliveryChallan::class, (int) $id);
    }

    public function destroy($id)
    {
        DB::transaction(function () use ($id) {
            $challan = DeliveryChallan::lockForUpdate()->findOrFail($id);
            abort_unless($challan->status === 'pending', 422, 'Converted or cancelled documents cannot be changed.');
            if ($challan->posted_at) {
                $this->unpostMaterial($challan, 'Cancelled before billing');
                $challan->forceFill(['status' => 'cancelled'])->save();
                return;
            }
            $challan->update(['status' => 'cancelled']);
            DeliveryChallanItem::where('delivery_challan_id', $challan->id)->delete();
            $challan->delete();
        });

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json(['success' => true, 'message' => 'Delivery Challan cancelled/deleted.']);
        }

        return redirect()->route('delivery-challans.index')->with('message', 'Delivery Challan cancelled/deleted.');
    }

    public function convertToSale(Request $request, $id)
    {
        $challan = DeliveryChallan::with(['items.product', 'items.unit'])->findOrFail($id);

        if ($challan->status === 'converted_to_sale') {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Challan has already been converted to a Sales Bill.'], 422);
            }
            return redirect()->back()->with('not_permitted', 'Challan has already been converted to a Sales Bill.');
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Challan ready for conversion.',
                'redirect_url' => route('sales.index', ['from_dc' => $challan->id]),
                'challan' => $challan,
            ]);
        }

        return redirect()->route('sales.index', ['from_dc' => $challan->id]);
    }
}
