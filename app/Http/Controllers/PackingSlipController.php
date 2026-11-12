<?php

namespace App\Http\Controllers;

use App\Services\Inventory\LegacyInventoryPosting;
use App\Services\Inventory\StockLine;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\Product_Sale;
use App\Models\PackingSlip;
use App\Models\PackingSlipProduct;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\Variant;
use App\Models\ProductVariant;
use App\Models\Delivery;
use DB;

class PackingSlipController extends Controller
{
    use \App\Http\Controllers\Concerns\NumbersLegacyDocuments;

	public function index()
	{
		return view('backend.packing_slip.index');
	}

	public function packingSlipData(Request $request)
    {
        $columns = array(
        	1 => 'reference_no',
            4 => 'amount',
        );

        $totalData = PackingSlip::count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'packing_slips.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        if(empty($request->input('search.value'))) {
            $packing_slips = PackingSlip::with('sale', 'delivery', 'products')->offset($start)
                    ->limit($limit)
                    ->orderBy(DB::raw('CAST('.$order.' AS SIGNED)'), $dir)
                    ->get();
        }
        else
        {
            $search = $request->input('search.value');
            if($search[0] == 'p' || $search[0] == 'P')
                $search = substr($search, 1);
            elseif($search[0] == 'n' || $search[0] == 'N')
                $search = strtoupper($search);

            $packing_slips = PackingSlip::select('packing_slips.*')
                        ->with('sale', 'delivery', 'products')
                        ->join('sales', 'packing_slips.sale_id', '=', 'sales.id')
                        ->whereNull('sales.deleted_at')
                        ->where('packing_slips.reference_no', 'LIKE', "%{$search}%")
                        ->orwhere('sales.reference_no', 'LIKE', "%{$search}%")
                        ->offset($start)
                        ->limit($limit)
                        ->orderBy(DB::raw('CAST('.$order.' AS SIGNED)'), $dir)
                        ->get();

            $totalFiltered = PackingSlip::
            				join('sales', 'packing_slips.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at')
	                        ->where('packing_slips.reference_no', 'LIKE', "%{$search}%")
	                        ->orwhere('sales.reference_no', 'LIKE', "%{$search}%")
	                        ->count();
        }

        $data = array();

        if(!empty($packing_slips))
        {
            foreach ($packing_slips as $key => $packing_slip)
            {
                $nestedData['id'] = $packing_slip->id;
                $nestedData['reference'] = 'P' . $packing_slip->reference_no;
                $nestedData['sale_reference'] = $packing_slip->sale->reference_no;
                $nestedData['delivery_reference'] = $packing_slip->delivery->reference_no;
                //$nestedData['delivery_reference'] = 'j';
                $nestedData['amount'] = $packing_slip->amount;
                $nestedData['item_list'] = '';
                $packing_slip_product = PackingSlipProduct::where([
                    ['packing_slip_id', $packing_slip->id],
                ])->get();
                foreach($packing_slip->products as $index => $product) {
                    $variant_id = $packing_slip_product[$index]->variant_id;
                    if($variant_id){
                        $variant = Variant::find($variant_id);
                        $product->name .= ' ['.$variant->name.']';
                    }
                    if($index)
                        $nestedData['item_list'] .= ', '.$product->name;
                    else
                        $nestedData['item_list'] = $product->name;
                }

                if ($packing_slip->status == 'In Transit')
                    $nestedData['status'] = '<div class="badge badge-warning">'.$packing_slip->status.'</div>';
                elseif ($packing_slip->status == 'Cancelled' || $packing_slip->status == 'Pending')
                    $nestedData['status'] = '<div class="badge badge-danger">'.$packing_slip->status.'</div>';
                else
                    $nestedData['status'] = '<div class="badge badge-success">'.$packing_slip->status.'</div>';

                $nestedData['options'] = '<div class="btn-group">
                                            <a target="_blank" class="btn btn-sm btn-primary" href="'.route('sale.invoice', $packing_slip->sale->id).'" title="Generate Invoice"><i class="dripicons-document-new"></i></a>&nbsp;&nbsp;
                                            <a target="_blank" class="btn btn-sm btn-dark" href="'.route('packingSlip.genInvoice', $packing_slip->id).'" title="Generate Shipping Label"><i class="dripicons-ticket"></i></a>&nbsp;&nbsp;';
                $nestedData['options'] .= \Form::open(["route" => ["packingSlip.delete", $packing_slip->id], "method" => "POST"] ).'<button type="submit" class="btn btn-danger btn-sm" onclick="return confirmDelete()"><i class="dripicons-trash"></i> </button>'.\Form::close().'</div>';

                $data[] = $nestedData;
            }
        }
        $json_data = array(
            "draw"            => intval($request->input('draw')),
            "recordsTotal"    => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data"            => $data
        );

        echo json_encode($json_data);
    }

    public function store(Request $request)
    {
    	$data = $request->all();
    	// return dd($data);
    	$packing_slip = PackingSlip::latest()->first();
        if(in_array('ecommerce',explode(',', config('addons'))))
    	    $sale = Sale::with('customer')->whereNull('deleted_at')->select('id', 'sale_status', 'customer_id', 'warehouse_id', 'shipping_name', 'shipping_address', 'shipping_city', 'shipping_country', 'sale_type')->find($data['sale_id']);
        else
            $sale = Sale::with('customer')->whereNull('deleted_at')->select('id', 'sale_status', 'customer_id', 'warehouse_id')->find($data['sale_id']);

        if($packing_slip)
    		$reference_no = $packing_slip->reference_no + 1;
    	else
    		$reference_no = 1001;

        DB::beginTransaction();
        try {
            $sale = Sale::whereKey($data['sale_id'])->lockForUpdate()->firstOrFail();
            $packing_slip = PackingSlip::create([
                                "reference_no" => $reference_no,
                                "sale_id" => $data['sale_id'],
                                "amount" => $data['amount'],
                                "status" => "Pending"
                            ]);
            
            $stockLines = [];
            foreach ($data['is_packing'] as $key => $product_info) {
                $product_info = explode("|", $product_info);
                $product_id = $product_info[0];
                $variant_id = $product_info[1];
                if(!$variant_id) {
                    if($sale->sale_type == 'online')
                        $variant_id = 0;
                    else
                        $variant_id = NULL;
                }

                PackingSlipProduct::create([
                    "packing_slip_id" => $packing_slip->id,
                    "product_id" => $product_id,
                    "variant_id" => $variant_id
                ]);
                $product_sale_data = Product_Sale::where([
                    ['sale_id', $data['sale_id']],
                    ['product_id', $product_id],
                    ['variant_id', $variant_id]
                ])->first();

                if (!$product_sale_data || $product_sale_data->is_packing) {
                    throw new \InvalidArgumentException('Sale line is missing or already packed.');
                }
                $product_sale_data->update(['is_packing' => true]);
                $stockLines = array_merge($stockLines, app(LegacyInventoryPosting::class)->saleLines($product_sale_data));
            }
            app(LegacyInventoryPosting::class)->post($packing_slip, 'issue', $stockLines, (int) $sale->warehouse_id);

            $delivery = Delivery::where('sale_id', $sale->id)->first();
            if(!$delivery) {
                //creating a new delivery
                $delivery = new Delivery();
                $deliveryReservation = $this->reserveNumber('delivery');
                $delivery->reference_no = $deliveryReservation->formatted_number;
                $delivery->sale_id = $sale->id;
                $delivery->user_id = \Auth::id();
                if($sale->shipping_address) {
                    $delivery->address = $sale->shipping_address;
                    if($sale->shipping_city)
                        $delivery->address .= ', '.$sale->shipping_city;
                    if($sale->shipping_country)
                        $delivery->address .= ', '.$sale->shipping_country;
                }
                elseif($sale->customer->address)
                    $delivery->address = $sale->customer->address;
                else
                    $delivery->address = 'No address available';
                if($sale->shipping_name) {
                    $delivery->recieved_by = $sale->shipping_name;
                }
                $delivery->status = 1;
                $delivery->packing_slip_ids = $packing_slip->id;
                $delivery->save();
                $this->assignNumber($deliveryReservation, $delivery);
            }
            else {
                $delivery->packing_slip_ids .= ','.$packing_slip->id;
                $delivery->save();
            }
            //updating packing slip
            $packing_slip->delivery_id = $delivery->id;
            $packing_slip->save();
            //updating sale status
            $sale->sale_status = 5;
            $sale->save();
            DB::commit();
        }
        catch(\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 422);
        }
    	return redirect()->back()->with('message', __('db.Packing slip created successfully'));
    }
    public function genInvoice($id)
    {
        $packing_slip = PackingSlip::with('sale.customer', 'sale.warehouse')->findOrFail($id);
        $sale = $packing_slip->sale;
        $general_setting = cache()->get('general_setting') ?? \App\Models\GeneralSetting::latest()->first();
        $packing_slip_product_data = \App\Models\Product_Sale::where('sale_id', $sale->id)->get();
        if ($packing_slip_product_data->isEmpty()) {
            $packing_slip_product_data = PackingSlipProduct::where('packing_slip_id', $id)->get();
        }
        return view('backend.packing_slip.invoice', compact('sale', 'packing_slip_product_data', 'general_setting'));
    }

    public function delete($id)
    {
        return DB::transaction(function () use ($id) {
            $packingSlip = PackingSlip::whereKey($id)->lockForUpdate()->firstOrFail();
            $sale = Sale::whereKey($packingSlip->sale_id)->lockForUpdate()->firstOrFail();
            $products = PackingSlipProduct::where('packing_slip_id', $id)->get();
            $posting = app(LegacyInventoryPosting::class);
            $posting->reverse($packingSlip, function () use ($posting, $packingSlip, $sale, $products) {
                $lines = [];
                foreach ($products as $product) {
                    if (Product::findOrFail($product->product_id)->type === 'combo') {
                        throw new \App\Services\Inventory\StockPolicyException('Historical combo packing slip requires a reviewed component snapshot before reversal.');
                    }
                    $saleLine = Product_Sale::where('sale_id', $sale->id)->where('product_id', $product->product_id)
                        ->where('variant_id', $product->variant_id)->firstOrFail();
                    $lines = array_merge($lines, $posting->saleLines($saleLine));
                }
                $posting->post($packingSlip, 'receive', $lines, (int) $sale->warehouse_id);
            });
            foreach ($products as $product) {
                Product_Sale::where('sale_id', $sale->id)->where('product_id', $product->product_id)
                    ->where('variant_id', $product->variant_id)->update(['is_packing' => false]);
                $product->delete();
            }
            $packingSlip->delete();
            $remaining = PackingSlip::where('sale_id', $sale->id)->pluck('id');
            $sale->update(['sale_status' => $remaining->isEmpty() ? 2 : 5]);
            if ($remaining->isEmpty()) {
                Delivery::where('sale_id', $sale->id)->delete();
            } else {
                Delivery::where('sale_id', $sale->id)->update(['packing_slip_ids' => $remaining->implode(',')]);
            }
            return redirect()->back()->with('message', __('db.Packing Slip deletes successfully'));
        });
    }
}
