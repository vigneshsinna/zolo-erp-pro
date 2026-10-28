<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ReturnPurchase;
use App\Models\Warehouse;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\Unit;
use App\Models\PurchaseProductReturn;
use App\Models\Account;
use App\Models\ProductVariant;
use App\Models\ProductBatch;
use App\Models\Variant;
use App\Models\Purchase;
use App\Models\ProductPurchase;
use App\Models\Currency;
use Auth;
use DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Mail\UserNotification;
use App\Models\Payment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use App\Traits\TenantInfo;
use App\Services\Inventory\LegacyInventoryPosting;

class ReturnPurchaseController extends Controller
{
    use TenantInfo;
    use \App\Http\Controllers\Concerns\NumbersLegacyDocuments;

    public function index(Request $request)
    {
        return redirect()->route('purchases.index', ['returns' => 1]);
    }

    public function returnData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $warehouse_id = $request->input('warehouse_id');

        if(Auth::user()->role_id > 2 && config('staff_access') == 'own')
            $totalData = ReturnPurchase::where('user_id', Auth::id())
                        ->whereDate('created_at', '>=' ,$request->input('starting_date'))
                        ->whereDate('created_at', '<=' ,$request->input('ending_date'))
                        ->count();
        elseif(Auth::user()->role_id > 2 && config('staff_access') == 'warehouse')
            $totalData = ReturnPurchase::where('warehouse_id', Auth::user()->warehouse_id)
                        ->whereDate('created_at', '>=' ,$request->input('starting_date'))
                        ->whereDate('created_at', '<=' ,$request->input('ending_date'))
                        ->count();
        elseif($warehouse_id != 0)
            $totalData = ReturnPurchase::where('warehouse_id', $warehouse_id)
                        ->whereDate('created_at', '>=' ,$request->input('starting_date'))
                        ->whereDate('created_at', '<=' ,$request->input('ending_date'))
                        ->count();
        else
            $totalData = ReturnPurchase::whereDate('created_at', '>=' ,$request->input('starting_date'))
                        ->whereDate('created_at', '<=' ,$request->input('ending_date'))
                        ->count();

        $totalFiltered = $totalData;
        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'return_purchases.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        if(empty($request->input('search.value'))) {
            $q = ReturnPurchase::with('supplier', 'warehouse', 'user')
                ->whereDate('created_at', '>=' ,$request->input('starting_date'))
                ->whereDate('created_at', '<=' ,$request->input('ending_date'))
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir);
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own')
                $q = $q->where('user_id', Auth::id());
            elseif(Auth::user()->role_id > 2 && config('staff_access') == 'warehouse')
                $q = $q->where('warehouse_id', Auth::user()->warehouse_id);
            elseif($warehouse_id != 0)
                $q = $q->where('warehouse_id', $warehouse_id);
            $returnss = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = ReturnPurchase::leftJoin('suppliers', 'return_purchases.supplier_id', '=', 'suppliers.id')
                ->whereDate('return_purchases.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))))
                ->offset($start)
                ->limit($limit)
                ->orderBy($order,$dir);
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $returnss =  $q->select('return_purchases.*')
                            ->with('supplier', 'warehouse', 'user')
                            ->where('return_purchases.user_id', Auth::id())
                            ->orwhere([
                                ['return_purchases.reference_no', 'LIKE', "%{$search}%"],
                                ['return_purchases.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['suppliers.name', 'LIKE', "%{$search}%"],
                                ['return_purchases.user_id', Auth::id()]
                            ])
                            ->get();

                $totalFiltered = $q->where('return_purchases.user_id', Auth::id())
                                ->orwhere([
                                    ['return_purchases.reference_no', 'LIKE', "%{$search}%"],
                                    ['return_purchases.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['suppliers.name', 'LIKE', "%{$search}%"],
                                    ['return_purchases.user_id', Auth::id()]
                                ])
                                ->count();
            }
            elseif(Auth::user()->role_id > 2 && config('staff_access') == 'warehouse') {
                $returnss =  $q->select('return_purchases.*')
                            ->with('supplier', 'warehouse', 'user')
                            ->where('return_purchases.user_id', Auth::id())
                            ->orwhere([
                                ['return_purchases.reference_no', 'LIKE', "%{$search}%"],
                                ['return_purchases.warehouse_id', Auth::user()->warehouse_id]
                            ])
                            ->orwhere([
                                ['suppliers.name', 'LIKE', "%{$search}%"],
                                ['return_purchases.warehouse_id', Auth::user()->warehouse_id]
                            ])
                            ->get();

                $totalFiltered = $q->where('return_purchases.user_id', Auth::id())
                                ->orwhere([
                                    ['return_purchases.reference_no', 'LIKE', "%{$search}%"],
                                    ['return_purchases.warehouse_id', Auth::user()->warehouse_id]
                                ])
                                ->orwhere([
                                    ['suppliers.name', 'LIKE', "%{$search}%"],
                                    ['return_purchases.warehouse_id', Auth::user()->warehouse_id]
                                ])
                                ->count();
            }
            else {
                $returnss =  $q->select('return_purchases.*')
                            ->with('supplier', 'warehouse', 'user')
                            ->orwhere('return_purchases.reference_no', 'LIKE', "%{$search}%")
                            ->orwhere('suppliers.name', 'LIKE', "%{$search}%")
                            ->get();

                $totalFiltered = $q->orwhere('return_purchases.reference_no', 'LIKE', "%{$search}%")
                                ->orwhere('suppliers.name', 'LIKE', "%{$search}%")
                                ->count();
            }
        }
        $data = array();
        if(!empty($returnss))
        {
            foreach ($returnss as $key=>$returns)
            {
                $nestedData['id'] = $returns->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($returns->created_at->toDateString()));
                $nestedData['reference_no'] = $returns->reference_no;
                $nestedData['warehouse'] = $returns->warehouse->name;
                if($returns->purchase_id) {
                    $purchase_data = Purchase::select('reference_no')->find($returns->purchase_id);
                    if($purchase_data)
                        $nestedData['purchase_reference'] = $purchase_data->reference_no;
                    else
                        $nestedData['purchase_reference'] = 'N/A';
                }
                else
                    $nestedData['purchase_reference'] = 'N/A';
                if($returns->supplier){
                    $supplier = $returns->supplier;
                    $nestedData['supplier'] = $returns->supplier->name;
                }
                else {
                    $supplier = new Supplier;
                    $nestedData['supplier'] = 'N/A';
                }
                $nestedData['grand_total'] = number_format($returns->grand_total / $returns->exchange_rate, config('decimal'));
                $nestedData['options'] = '<div class="btn-group">
                            <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'.__("db.action").'
                              <span class="caret"></span>
                              <span class="sr-only">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" user="menu">
                                <li>
                                    <button type="button" class="btn btn-link view"><i class="fa fa-eye"></i> '.__('db.View').'</button>
                                </li>';
                if(in_array("returns-edit", $request['all_permission'])) {
                    $nestedData['options'] .= '<li>
                        <a href="'.route('return-purchase.edit', $returns->id).'" class="btn btn-link"><i class="dripicons-document-edit"></i> '.__('db.edit').'</a>
                        </li>';
                }
                if(in_array("returns-delete", $request['all_permission']))
                    $nestedData['options'] .= \Form::open(["route" => ["return-purchase.destroy", $returns->id], "method" => "DELETE"] ).'
                            <li>
                              <button type="submit" class="btn btn-link" onclick="return confirmDelete()"><i class="dripicons-trash"></i> '.__("db.delete").'</button>
                            </li>'.\Form::close().'
                        </ul>
                    </div>';
                // data for purchase details by one click

                if($returns->currency_id)
                    $currency_code = Currency::select('code')->find($returns->currency_id)->code;
                else
                    $currency_code = 'N/A';

                $nestedData['return'] = array( '[ "'.date(config('date_format'), strtotime($returns->created_at->toDateString())).'"', ' "'.$returns->reference_no.'"', ' "'.$returns->warehouse->name.'"', ' "'.$returns->warehouse->phone.'"', ' "'.preg_replace('/[\n\r]/', "<br>", $returns->warehouse->address).'"', ' "'.$supplier->name.'"', ' "'.$supplier->company_name.'"', ' "'.$supplier->email.'"', ' "'.$supplier->phone_number.'"', ' "'.preg_replace('/[\n\r]/', "<br>", $supplier->address).'"', ' "'.$supplier->city.'"', ' "'.$returns->id.'"', ' "'.$returns->total_tax.'"', ' "'.$returns->total_discount.'"', ' "'.$returns->total_cost.'"', ' "'.$returns->order_tax.'"', ' "'.$returns->order_tax_rate.'"', ' "'.$returns->grand_total.'"', ' "'.preg_replace('/[\n\r]/', "<br>", $returns->return_note).'"', ' "'.preg_replace('/[\n\r]/', "<br>", $returns->staff_note).'"', ' "'.$returns->user->name.'"', ' "'.$returns->user->email.'"', ' "'.$nestedData['purchase_reference'].'"', ' "'.$returns->document.'"', ' "'.$currency_code.'"', ' "'.$returns->exchange_rate.'"]'
                );
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

    public function create(Request $request)
    {
        // Purchase returns are the Returns tab of the Purchase workspace; this old form was a duplicate.
        $purchaseId = \App\Models\Purchase::where('reference_no', $request->input('reference_no', ''))->value('id');
        return redirect()->route('purchases.index', array_filter(['returns' => 1, 'bill' => $purchaseId]));
    }

    public function getProduct($id)
    {
        //retrieve data of product without variant
        $lims_product_warehouse_data = DB::table('products')
            ->join('product_warehouse', 'products.id', '=', 'product_warehouse.product_id')
            ->select('products.code', 'products.name', 'products.type', 'product_warehouse.qty')
            ->where([
                ['product_warehouse.warehouse_id', $id],
                ['products.is_active', true]
            ])
            ->whereNull('product_warehouse.variant_id')
            ->whereNull('product_warehouse.product_batch_id')
            ->get();

        config()->set('database.connections.mysql.strict', false);
        \DB::reconnect(); //important as the existing connection if any would be in strict mode

        //retrieve data of product with batch
        $lims_product_with_batch_warehouse_data = Product::join('product_warehouse', 'products.id', '=', 'product_warehouse.product_id')
        ->where([
            ['products.is_active', true],
            ['product_warehouse.warehouse_id', $id],
        ])
        ->whereNull('product_warehouse.variant_id')
        ->whereNotNull('product_warehouse.product_batch_id')
        ->select('product_warehouse.*')
        ->groupBy('product_warehouse.product_id')
        ->get();

        //now changing back the strict ON
        config()->set('database.connections.mysql.strict', true);
        \DB::reconnect();

        //retrieve data of product with variant
        $lims_product_with_variant_warehouse_data = DB::table('products')
            ->join('product_warehouse', 'products.id', '=', 'product_warehouse.product_id')
            ->select('products.id', 'products.code', 'products.name', 'products.type', 'product_warehouse.qty', 'product_warehouse.variant_id')
            ->where([
                ['product_warehouse.warehouse_id', $id],
                ['products.is_active', true]
            ])
            ->whereNotNull('product_warehouse.variant_id')
            ->get();

        $product_code = [];
        $product_name = [];
        $product_qty = [];
        $is_batch = [];
        $product_data = [];
        foreach ($lims_product_warehouse_data as $product_warehouse)
        {
            $product_qty[] = $product_warehouse->qty;
            $product_code[] =  $product_warehouse->code;
            $product_name[] = $product_warehouse->name;
            $product_type[] = $product_warehouse->type;
            $is_batch[] = null;
        }
        //product with batches
        foreach ($lims_product_with_batch_warehouse_data as $product_warehouse)
        {
            $product_qty[] = $product_warehouse->qty;
            $lims_product_data = Product::select('code', 'name', 'type', 'is_batch')->find($product_warehouse->product_id);
            $product_code[] =  $lims_product_data->code;
            $product_name[] = htmlspecialchars($lims_product_data->name);
            $product_type[] = $lims_product_data->type;
            $product_batch_data = ProductBatch::select('id', 'batch_no')->find($product_warehouse->product_batch_id);
            $is_batch[] = $lims_product_data->is_batch;
        }

        foreach ($lims_product_with_variant_warehouse_data as $product_warehouse)
        {
            $lims_product_variant_data = ProductVariant::select('item_code')->FindExactProduct($product_warehouse->id, $product_warehouse->variant_id)->first();
            $product_qty[] = $product_warehouse->qty;
            $product_code[] =  $lims_product_variant_data->item_code;
            $product_name[] = $product_warehouse->name;
            $product_type[] = $product_warehouse->type;
            $is_batch[] = null;
        }

        $product_data = [$product_code, $product_name, $product_qty, $product_type, $is_batch];
        return $product_data;
    }

    public function limsProductSearch(Request $request)
    {
        $product_code = explode("(", $request['data']);
        $product_code[0] = rtrim($product_code[0], " ");
        $lims_product_data = Product::where('code', $product_code[0])->first();
        $product_variant_id = null;
        if(!$lims_product_data) {
            $lims_product_data = Product::join('product_variants', 'products.id', 'product_variants.product_id')
                ->select('products.*', 'product_variants.id as product_variant_id', 'product_variants.item_code', 'product_variants.additional_cost')
                ->where('product_variants.item_code', $product_code[0])
                ->first();
            $lims_product_data->code = $lims_product_data->item_code;
            $lims_product_data->cost += $lims_product_data->additional_cost;
            $product_variant_id = $lims_product_data->product_variant_id;
        }

        $product[] = $lims_product_data->name;
        $product[] = $lims_product_data->code;
        $product[] = $lims_product_data->cost;

        if ($lims_product_data->tax_id) {
            $lims_tax_data = Tax::find($lims_product_data->tax_id);
            $product[] = $lims_tax_data->rate;
            $product[] = $lims_tax_data->name;
        } else {
            $product[] = 0;
            $product[] = 'No Tax';
        }
        $product[] = $lims_product_data->tax_method;

        $units = Unit::where("base_unit", $lims_product_data->unit_id)
                    ->orWhere('id', $lims_product_data->unit_id)
                    ->get();

        $unit_name = array();
        $unit_operator = array();
        $unit_operation_value = array();
        foreach ($units as $unit) {
            if ($lims_product_data->purchase_unit_id == $unit->id) {
                array_unshift($unit_name, $unit->unit_name);
                array_unshift($unit_operator, $unit->operator);
                array_unshift($unit_operation_value, $unit->operation_value);
            } else {
                $unit_name[]  = $unit->unit_name;
                $unit_operator[] = $unit->operator;
                $unit_operation_value[] = $unit->operation_value;
            }
        }

        $product[] = implode(",", $unit_name) . ',';
        $product[] = implode(",", $unit_operator) . ',';
        $product[] = implode(",", $unit_operation_value) . ',';
        $product[] = $lims_product_data->id;
        $product[] = $product_variant_id;
        $product[] = $lims_product_data->is_imei;
        return $product;
    }

    public function store(Request $request)
    {
        return DB::transaction(fn () => $this->storeReturn($request));
    }

    private function storeReturn(Request $request)
    {
        $data = $request->except('document');
        $numberReservation = $this->reserveNumber('purchase_debit_note', $request->input('created_at'));
        $data['reference_no'] = $numberReservation->formatted_number;
        $data['user_id'] = Auth::id();
        $lims_purchase_data = Purchase::select('warehouse_id', 'supplier_id', 'currency_id', 'exchange_rate')->find($data['purchase_id']);
        $data['user_id'] = Auth::id();
        $data['supplier_id'] = $lims_purchase_data->supplier_id;
        $data['warehouse_id'] = $lims_purchase_data->warehouse_id;
        $data['currency_id'] = $lims_purchase_data->currency_id;
        $data['exchange_rate'] = $lims_purchase_data->exchange_rate;
        $document = $request->document;
        if ($document) {
            $v = Validator::make(
                [
                    'extension' => strtolower($request->document->getClientOriginalExtension()),
                ],
                [
                    'extension' => 'in:jpg,jpeg,png,gif,pdf,csv,docx,xlsx,txt',
                ]
            );
            if ($v->fails())
                return redirect()->back()->withErrors($v->errors());

            $ext = pathinfo($document->getClientOriginalName(), PATHINFO_EXTENSION);
            $documentName = date("Ymdhis");
            if(!config('database.connections.zoloerp_landlord')) {
                $documentName = $documentName . '.' . $ext;
                $document->move(public_path('documents/purchase_return'), $documentName);
            }
            else {
                $documentName = $this->getTenantId() . '_' . $documentName . '.' . $ext;
                $document->move(public_path('documents/purchase_return'), $documentName);
            }
            $data['document'] = $documentName;
        }

        $lims_return_data = ReturnPurchase::create($data);
        $this->assignNumber($numberReservation, $lims_return_data);
        $mail_data['email'] = '';
        if($data['supplier_id']) {
            $lims_supplier_data = Supplier::find($data['supplier_id']);
            //collecting male data
            $mail_data['email'] = $lims_supplier_data->email;
            $mail_data['reference_no'] = $lims_return_data->reference_no;
            $mail_data['total_qty'] = $lims_return_data->total_qty;
            $mail_data['total_price'] = $lims_return_data->total_price;
            $mail_data['order_tax'] = $lims_return_data->order_tax;
            $mail_data['order_tax_rate'] = $lims_return_data->order_tax_rate;
            $mail_data['grand_total'] = $lims_return_data->grand_total;
        }

        $product_purchase_ids = $data['is_return'];
        $imei_number = $data['imei_number'];
        $product_batch_id = $data['product_batch_id'];
        $product_code = $data['product_code'];
        $qty = $data['qty'];
        $purchase_unit = $data['purchase_unit'];
        $net_unit_cost = $data['net_unit_cost'];
        $discount = $data['discount'];
        $tax_rate = $data['tax_rate'];
        $tax = $data['tax'];
        $total = $data['subtotal'];

        foreach ($product_purchase_ids as $product_purchase_id) {
            $key = array_search($product_purchase_id, $data['product_purchase_id']);
            $pro_id = $data['product_id'][$key];
            //return $key;
            $lims_product_data = Product::find($pro_id);
            $variant_id = null;
            if($purchase_unit[$key] != 'n/a') {
                $lims_purchase_unit_data  = Unit::where('unit_name', $purchase_unit[$key])->first();
                $purchase_unit_id = $lims_purchase_unit_data->id;
                if($lims_product_data->is_variant) {
                    $lims_product_variant_data = ProductVariant::select('id', 'variant_id')->FindExactProductWithCode($pro_id, $product_code[$key])->first();
                    $variant_data = Variant::find($lims_product_variant_data->variant_id);
                    $variant_id = $variant_data->id;
                }
            }
            else
                $purchase_unit_id = 0;
            if($lims_product_data->is_variant)
                $mail_data['products'][$key] = $lims_product_data->name . ' [' . $variant_data->name . ']';
            else
                $mail_data['products'][$key] = $lims_product_data->name;

            if($purchase_unit_id)
                $mail_data['unit'][$key] = $lims_purchase_unit_data->unit_code;
            else
                $mail_data['unit'][$key] = '';

            $mail_data['qty'][$key] = $qty[$key];
            $mail_data['total'][$key] = $total[$key];
            PurchaseProductReturn::create(
                ['return_id' => $lims_return_data->id, 'product_id' => $pro_id, 'product_batch_id' => $product_batch_id[$key], 'variant_id' => $variant_id, 'imei_number' => $imei_number[$key], 'qty' => $qty[$key], 'purchase_unit_id' => $purchase_unit_id, 'net_unit_cost' => $net_unit_cost[$key], 'discount' => $discount[$key], 'tax_rate' => $tax_rate[$key], 'tax' => $tax[$key], 'total' => $total[$key], 'created_at' => \Carbon\Carbon::now(),  'updated_at' => \Carbon\Carbon::now()]
            );
            $product_purchase_data = ProductPurchase::where([
                ['product_id', $pro_id],
                ['purchase_id', $data['purchase_id']]
            ])->select('id', 'return_qty')->first();
            $product_purchase_data->return_qty += $qty[$key];
            $product_purchase_data->save();
        }
        $this->postPurchaseReturnStock($lims_return_data);
        $message = 'Return created successfully';
        if($mail_data['email']){
            try{
                Mail::send( 'mail.return_details', $mail_data, function( $message ) use ($mail_data)
                {
                    $message->to( $mail_data['email'] )->subject( 'Return Details' );
                });
            }
            catch(\Exception $e){
                $message = 'Return created successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }
        app(\App\Services\Commercial\CommercialDraftService::class)->forgetPosted('preturn', $request->integer('draft_id'), (int) Auth::id());
        return redirect('return-purchase')->with('message', $message);
    }

    /** A purchase return sends the returned goods back out of the purchase's warehouse. */
    private function postPurchaseReturnStock(ReturnPurchase $return): void
    {
        $posting = app(LegacyInventoryPosting::class);
        $posting->post($return, 'issue', $posting->purchaseReturnLines(PurchaseProductReturn::where('return_id', $return->id)->get()), (int) $return->warehouse_id);
    }

    /** Put the returned goods back: the applied movements, or the persisted lines for pre-cutover history. */
    private function reversePurchaseReturnStock(ReturnPurchase $return): void
    {
        $posting = app(LegacyInventoryPosting::class);
        $posting->reverse($return, function () use ($posting, $return) {
            $posting->post($return, 'receive', $posting->purchaseReturnLines(PurchaseProductReturn::where('return_id', $return->id)->get()), (int) $return->warehouse_id);
        });
    }

    public function productReturnData($id)
    {
        $lims_product_return_data = PurchaseProductReturn::where('return_id', $id)->get();
        foreach ($lims_product_return_data as $key => $product_return_data) {
            $product = Product::find($product_return_data->product_id);
            if($product_return_data->purchase_unit_id != 0){
                $unit_data = Unit::find($product_return_data->purchase_unit_id);
                $unit = $unit_data->unit_code;
            }
            else
                $unit = '';

            if($product_return_data->variant_id) {
                $lims_product_variant_data = ProductVariant::select('item_code')->FindExactProduct($product_return_data->product_id, $product_return_data->variant_id)->first();
                $product->code = $lims_product_variant_data->item_code;
            }
            if($product_return_data->product_batch_id) {
                $product_batch_data = ProductBatch::select('batch_no')->find($product_return_data->product_batch_id);
                $product_return[7][$key] = $product_batch_data->batch_no;
            }
            else
                $product_return[7][$key] = 'N/A';
            $product_return[0][$key] = $product->name . ' [' . $product->code . ']';
            if($product_return_data->imei_number)
                $product_return[0][$key] .= '<br>IMEI or Serial Number: '.$product_return_data->imei_number;
            $product_return[1][$key] = $product_return_data->qty;
            $product_return[2][$key] = $unit;
            $product_return[3][$key] = $product_return_data->tax;
            $product_return[4][$key] = $product_return_data->tax_rate;
            $product_return[5][$key] = $product_return_data->discount;
            $product_return[6][$key] = $product_return_data->total;
        }
        return $product_return;
    }

    public function sendMail(Request $request)
    {
        $data = $request->all();
        $lims_return_data = ReturnPurchase::find($data['return_id']);
        //return $lims_return_data;
        $lims_product_return_data = PurchaseProductReturn::where('return_id', $data['return_id'])->get();
        if($lims_return_data->supplier_id){
            $lims_supplier_data = Supplier::find($lims_return_data->supplier_id);
            //collecting male data
            $mail_data['email'] = $lims_supplier_data->email;
            $mail_data['reference_no'] = $lims_return_data->reference_no;
            $mail_data['total_qty'] = $lims_return_data->total_qty;
            $mail_data['total_price'] = $lims_return_data->total_cost;
            $mail_data['order_tax'] = $lims_return_data->order_tax;
            $mail_data['order_tax_rate'] = $lims_return_data->order_tax_rate;
            $mail_data['grand_total'] = $lims_return_data->grand_total;

            foreach ($lims_product_return_data as $key => $product_return_data) {
                $lims_product_data = Product::find($product_return_data->product_id);
                if($product_return_data->variant_id) {
                    $variant_data = Variant::find($product_return_data->variant_id);
                    $mail_data['products'][$key] = $lims_product_data->name . ' [' . $variant_data->name . ']';
                }
                else
                    $mail_data['products'][$key] = $lims_product_data->name;

                if($product_return_data->purchase_unit_id){
                    $lims_unit_data = Unit::find($product_return_data->purchase_unit_id);
                    $mail_data['unit'][$key] = $lims_unit_data->unit_code;
                }
                else
                    $mail_data['unit'][$key] = '';

                $mail_data['qty'][$key] = $product_return_data->qty;
                $mail_data['total'][$key] = $product_return_data->qty;
            }
            try{
                Mail::send( 'mail.return_details', $mail_data, function( $message ) use ($mail_data)
                {
                    $message->to( $mail_data['email'] )->subject( 'Return Details' );
                });
                $message = 'Mail sent successfully';
            }
            catch(\Exception $e){
                $message = 'Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }
        else
            $message = "This return doesn't belong to any supplier";

        return redirect()->back()->with('message', $message);
    }

    public function edit($id)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('purchase-return-edit')){
            $lims_supplier_list = Supplier::where('is_active',true)->get();
            $lims_warehouse_list = Warehouse::where('is_active',true)->get();
            $lims_account_list = Account::where('is_active',true)->get();
            $lims_tax_list = Tax::where('is_active',true)->get();
            $lims_return_data = ReturnPurchase::findOrFail($id);
            $lims_product_return_data = PurchaseProductReturn::where('return_id', $id)->get();
            return view('backend.return_purchase.edit',compact('lims_supplier_list', 'lims_warehouse_list', 'lims_tax_list', 'lims_account_list', 'lims_return_data','lims_product_return_data'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function update(Request $request, $id)
    {
        return DB::transaction(fn () => $this->updateReturn($request, $id));
    }

    private function updateReturn(Request $request, $id)
    {
        $data = $request->except('document');
        //return dd($data);
        $document = $request->document;
        $lims_return_data = ReturnPurchase::whereKey($id)->lockForUpdate()->firstOrFail();

        if ($document) {
            $v = Validator::make(
                [
                    'extension' => strtolower($request->document->getClientOriginalExtension()),
                ],
                [
                    'extension' => 'in:jpg,jpeg,png,gif,pdf,csv,docx,xlsx,txt',
                ]
            );
            if ($v->fails())
                return redirect()->back()->withErrors($v->errors());

            $this->fileDelete(public_path('documents/purchase_return/'), $lims_return_data->document);

            $ext = pathinfo($document->getClientOriginalName(), PATHINFO_EXTENSION);
            $documentName = date("Ymdhis");
            if(!config('database.connections.zoloerp_landlord')) {
                $documentName = $documentName . '.' . $ext;
                $document->move(public_path('documents/purchase_return'), $documentName);
            }
            else {
                $documentName = $this->getTenantId() . '_' . $documentName . '.' . $ext;
                $document->move(public_path('documents/purchase_return'), $documentName);
            }
            $data['document'] = $documentName;
        }

        $lims_product_return_data = PurchaseProductReturn::where('return_id', $id)->get();

        $product_id = $data['product_id'];
        $imei_number = $data['imei_number'];
        $product_batch_id = $data['product_batch_id'];
        $product_code = $data['product_code'];
        $product_variant_id = $data['product_variant_id'];
        $qty = $data['qty'];
        $purchase_unit = $data['purchase_unit'];
        $net_unit_cost = $data['net_unit_cost'];
        $discount = $data['discount'];
        $tax_rate = $data['tax_rate'];
        $tax = $data['tax'];
        $total = $data['subtotal'];

        // Put back what the return issued before its lines change; the new lines are issued after the update.
        $this->reversePurchaseReturnStock($lims_return_data);
        foreach ($lims_product_return_data as $key => $product_return_data) {
            $old_product_id[] = $product_return_data->product_id;
            $old_product_variant_id[] = null;
            if($product_return_data->variant_id) {
                $old_product_variant_id[$key] = ProductVariant::select('id')->FindExactProduct($product_return_data->product_id, $product_return_data->variant_id)->first()?->id;
            }
            if($product_return_data->variant_id && !(in_array($old_product_variant_id[$key], $product_variant_id)) ){
                $product_return_data->delete();
            }
            elseif( !(in_array($old_product_id[$key], $product_id)) )
                $product_return_data->delete();
        }
        foreach ($product_id as $key => $pro_id) {
            $lims_product_data = Product::find($pro_id);
            $product_return['variant_id'] = null;
            if($purchase_unit[$key] != 'n/a'){
                $lims_purchase_unit_data = Unit::where('unit_name', $purchase_unit[$key])->first();
                $purchase_unit_id = $lims_purchase_unit_data->id;
                if($lims_product_data->is_variant) {
                    $lims_product_variant_data = ProductVariant::select('id', 'variant_id')->FindExactProductWithCode($pro_id, $product_code[$key])->first();
                    $variant_data = Variant::find($lims_product_variant_data->variant_id);

                    $product_return['variant_id'] = $lims_product_variant_data->variant_id;
                }
            }
            else
                $purchase_unit_id = 0;

            if($lims_product_data->is_variant)
                $mail_data['products'][$key] = $lims_product_data->name . ' [' . $variant_data->name .']';
            else
                $mail_data['products'][$key] = $lims_product_data->name;

            if($purchase_unit_id)
                $mail_data['unit'][$key] = $lims_purchase_unit_data->unit_code;
            else
                $mail_data['unit'][$key] = '';

            $mail_data['qty'][$key] = $qty[$key];
            $mail_data['total'][$key] = $total[$key];

            $product_return['return_id'] = $id ;
            $product_return['product_id'] = $pro_id;
            $product_return['imei_number'] = $imei_number[$key];
            $product_return['product_batch_id'] = $product_batch_id[$key];
            $product_return['qty'] = $qty[$key];
            $product_return['purchase_unit_id'] = $purchase_unit_id;
            $product_return['net_unit_cost'] = $net_unit_cost[$key];
            $product_return['discount'] = $discount[$key];
            $product_return['tax_rate'] = $tax_rate[$key];
            $product_return['tax'] = $tax[$key];
            $product_return['total'] = $total[$key];

            if($product_return['variant_id'] && in_array($product_variant_id[$key], $old_product_variant_id)) {
                PurchaseProductReturn::where([
                    ['product_id', $pro_id],
                    ['variant_id', $product_return['variant_id']],
                    ['return_id', $id]
                ])->update($product_return);
            }
            elseif( $product_return['variant_id'] === null && (in_array($pro_id, $old_product_id)) ) {
                PurchaseProductReturn::where([
                    ['return_id', $id],
                    ['product_id', $pro_id]
                    ])->update($product_return);
            }
            else
                PurchaseProductReturn::create($product_return);
        }
        $lims_return_data->update($data);
        $this->postPurchaseReturnStock($lims_return_data->refresh());
        $message = 'Return updated successfully';
        if($data['supplier_id']) {
            $lims_supplier_data = Supplier::find($data['supplier_id']);
            //collecting male data
            $mail_data['email'] = $lims_supplier_data->email;
            $mail_data['reference_no'] = $lims_return_data->reference_no;
            $mail_data['total_qty'] = $lims_return_data->total_qty;
            $mail_data['total_price'] = $lims_return_data->total_cost;
            $mail_data['order_tax'] = $lims_return_data->order_tax;
            $mail_data['order_tax_rate'] = $lims_return_data->order_tax_rate;
            $mail_data['grand_total'] = $lims_return_data->grand_total;

            try{
                Mail::send( 'mail.return_details', $mail_data, function( $message ) use ($mail_data)
                {
                    $message->to( $mail_data['email'] )->subject( 'Return Details' );
                });
            }
            catch(\Exception $e){
                $message = 'Return updated successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }
        return redirect('return-purchase')->with('message', $message);
    }

    public function deleteBySelection(Request $request)
    {
        return DB::transaction(fn () => $this->deleteSelectedReturns($request));
    }

    private function deleteSelectedReturns(Request $request)
    {
        $return_id = $request['returnIdArray'];
        foreach ($return_id as $id) {
            $lims_return_data = ReturnPurchase::whereKey($id)->lockForUpdate()->firstOrFail();
            $lims_product_return_data = PurchaseProductReturn::where('return_id', $id)->get();

            // Put back what the return issued before its lines are removed.
            $this->reversePurchaseReturnStock($lims_return_data);
            foreach ($lims_product_return_data as $key => $product_return_data) {
                $product_return_data->delete();
            }
            $lims_return_data->delete();
            $this->fileDelete(public_path('documents/purchase_return/'), $lims_return_data->document);
        }
        return 'Return deleted successfully!';
    }

    public function destroy($id)
    {
        return DB::transaction(fn () => $this->destroyReturn($id));
    }

    private function destroyReturn($id)
    {
        $lims_return_data = ReturnPurchase::whereKey($id)->lockForUpdate()->firstOrFail();
        $lims_product_return_data = PurchaseProductReturn::where('return_id', $id)->get();

        // Put back what the return issued before its lines are removed.
        $this->reversePurchaseReturnStock($lims_return_data);
        foreach ($lims_product_return_data as $key => $product_return_data) {
            $product_return_data->delete();
        }
        $lims_return_data->delete();
        $this->fileDelete(public_path('documents/purchase_return/'), $lims_return_data->document);

        return redirect('return-purchase')->with('not_permitted', __('db.Data deleted successfully'));
    }
}
