<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Stripe\Stripe;
use App\Models\Tax;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\Brand;
use App\Models\Category;
use App\Models\User;
use App\Models\Account;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Variant;
use App\Models\Currency;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\PosSetting;
use App\Traits\TenantInfo;
use App\Helpers\DateHelper;
use App\Models\CustomField;
use App\Traits\StaffAccess;
use App\Models\Product_Sale;
use App\Models\ProductBatch;
use Illuminate\Http\Request;
use App\Models\GeneralSetting;
use App\Models\ProductVariant;
use App\Models\ProductPurchase;
use App\Services\PaymentService;
use App\Models\PaymentWithCheque;
use App\Models\Product_Warehouse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Auth;
use App\Models\PaymentWithCreditCard;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\Purchase\StorePurchaseRequest;
use App\Http\Requests\Purchase\UpdatePurchaseRequest;
use App\Services\Inventory\LegacyInventoryPosting;

class PurchaseController extends Controller
{
    use TenantInfo, StaffAccess;
    use \App\Http\Controllers\Concerns\NumbersLegacyDocuments;

    public function __construct()
    {
        $this->middleware(\App\Http\Middleware\CommercialRouteAdapter::class);
    }

    public function index(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('purchases-index')) {
            if($request->input('warehouse_id'))
                $warehouse_id = $request->input('warehouse_id');
            else
                $warehouse_id = 0;

            if($request->input('purchase_status'))
                $purchase_status = $request->input('purchase_status');
            else
                $purchase_status = 0;

            if($request->input('payment_status'))
                $payment_status = $request->input('payment_status');
            else
                $payment_status = 0;

            if($request->input('starting_date')) {
                $starting_date = $request->input('starting_date');
                $ending_date = $request->input('ending_date');
            }
            else {
                $starting_date = date("Y-m-d", strtotime(date('Y-m-d', strtotime('-1 year', strtotime(date('Y-m-d') )))));
                $ending_date = date("Y-m-d");
            }
            $permissions = Role::findByName($role->name)->permissions;
            foreach ($permissions as $permission)
                $all_permission[] = $permission->name;
            if(empty($all_permission))
                $all_permission[] = 'dummy text';
            $lims_pos_setting_data = PosSetting::select('stripe_public_key')->latest()->first();
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            $lims_account_list = Account::where('is_active', true)->get();
            $custom_fields = CustomField::where([
                                ['belongs_to', 'purchase'],
                                ['is_table', true]
                            ])->pluck('name');
            $field_name = [];
            foreach($custom_fields as $fieldName) {
                $field_name[] = str_replace(" ", "_", strtolower($fieldName));
            }
            $currency_list = Currency::where('is_active', true)->get();
            $todayPurchasesAmount = Purchase::whereDate('created_at', date('Y-m-d'))->sum('grand_total');
            $todayPurchasesCount = Purchase::whereDate('created_at', date('Y-m-d'))->count();
            $totalPaid = Purchase::sum('paid_amount');
            $totalDue = Purchase::selectRaw('SUM(grand_total - paid_amount) as due')->value('due') ?? 0;
            $lims_supplier_list = Supplier::where('is_active', true)->select('id', 'name', 'company_name', 'phone_number', 'tax_no', 'address', 'city', 'state', 'postal_code', 'credit_days')->limit(200)->get();
            $recent_bills = Purchase::with('supplier:id,name,company_name,phone_number')
                ->latest('id')
                ->limit(50)
                ->get();
            $lims_tax_list = Tax::where('is_active', true)->get();
            $lims_product_list_without_variant = $this->productWithoutVariant();
            $lims_product_list_with_variant = $this->productWithVariant();
            $currency = Currency::where('is_active', true)->first() ?? (object)['id' => 1, 'exchange_rate' => 1, 'code' => 'INR'];
            $purchaseTypes = class_exists(\App\Models\PurchaseType::class) ? \App\Models\PurchaseType::where('is_active', true)->get() : collect();
            $documentSeries = class_exists(\App\Models\DocumentSeries::class) ? \App\Models\DocumentSeries::where('document_type', 'purchase')->orWhereNull('document_type')->get() : collect();
            $billSundries = class_exists(\App\Models\BillSundry::class) ? \App\Models\BillSundry::where('is_active', true)->get() : collect();
            $standardRemarks = class_exists(\App\Models\StandardRemark::class) ? \App\Models\StandardRemark::where('is_active', true)->get() : collect();
            $agents = class_exists(\App\Models\Agent::class) ? \App\Models\Agent::where('is_active', true)->get() : collect();
            $areas = class_exists(\App\Models\Area::class) ? \App\Models\Area::where('is_active', true)->get() : collect();
            $pending_grns = class_exists(\App\Models\GoodsReceivedNote::class) ? \App\Models\GoodsReceivedNote::with(['supplier:id,name', 'items.product', 'items.unit'])->where('status', 'pending')->latest('id')->limit(30)->get() : collect();
            $lims_brand_list = Brand::where('is_active', true)->get();
            $lims_category_list = Category::where('is_active', true)->get();
            $lims_unit_list = Unit::where('is_active', true)->get();
            $lims_customer_group_all = class_exists(\App\Models\CustomerGroup::class) ? \App\Models\CustomerGroup::where('is_active', true)->get() : collect();
            $documentContext = app(\App\Services\Commercial\DocumentContextResolver::class)->fromRequest($request, 'purchase', request()->attributes->get(\App\Services\Platform\CompanyContext::class), (int) \Auth::id());
            return view('backend.purchase.index', compact( 'lims_account_list', 'lims_warehouse_list', 'all_permission', 'lims_pos_setting_data', 'warehouse_id', 'starting_date', 'ending_date', 'purchase_status', 'payment_status', 'custom_fields', 'field_name', 'currency_list', 'todayPurchasesAmount', 'todayPurchasesCount', 'totalPaid', 'totalDue', 'lims_supplier_list', 'recent_bills', 'lims_tax_list', 'lims_product_list_without_variant', 'lims_product_list_with_variant', 'currency', 'purchaseTypes', 'documentSeries', 'billSundries', 'standardRemarks', 'agents', 'areas', 'pending_grns', 'lims_brand_list', 'lims_category_list', 'lims_unit_list', 'lims_customer_group_all'))->with('documentContext', $documentContext);

        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function show($id)
    {
        $purchase = Purchase::with(['supplier', 'warehouse', 'payments'])->find($id);
        if (!$purchase) {
            return response()->json(['success' => false, 'message' => 'Purchase not found'], 404);
        }

        $lims_product_purchase_data = ProductPurchase::where('purchase_id', $id)->get();
        $items = [];
        foreach ($lims_product_purchase_data as $pp) {
            $product = Product::find($pp->product_id);
            $unit = Unit::find($pp->purchase_unit_id);
            $items[] = [
                'id' => $pp->id,
                'product_id' => $pp->product_id,
                'product_name' => $product->name ?? 'Unknown',
                'product_code' => $product->code ?? '',
                'qty' => (float)$pp->qty,
                'net_unit_cost' => (float)$pp->net_unit_cost,
                'discount' => (float)$pp->discount,
                'tax_rate' => (float)$pp->tax_rate,
                'tax' => (float)$pp->tax,
                'total' => (float)$pp->total,
                'unit_code' => $unit->unit_code ?? ($unit->unit_name ?? 'Unit'),
                'purchase_unit_id' => $pp->purchase_unit_id,
                'return_qty' => (float) $pp->return_qty,
                'product_batch_id' => $pp->product_batch_id,
                'expired_date' => $pp->product_batch_id ? ProductBatch::where('id', $pp->product_batch_id)->value('expired_date') : null,
                'batch_no' => $pp->product_batch_id ? (ProductBatch::where('id', $pp->product_batch_id)->value('batch_no') ?? '') : '',
                'imei_number' => $pp->imei_number ?? '',
            ];
        }

        return response()->json([
            'success' => true,
            'purchase' => $purchase,
            'items' => $items,
        ]);
    }

    private function isImeiExist(string $imei, string $product_id): bool
    {
        $product_warehouses = Product_Warehouse::where('product_id', $product_id)->get();

        foreach ($product_warehouses as $p) {
            $imeis = explode(',', $p->imei_number);
            if (in_array(trim($imei), array_map('trim', $imeis))) {
                return true;
            }
        }

        return false;
    }

    public function create()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('purchases-add')){
            $lims_supplier_list = Supplier::where('is_active', true)->get();
            if(Auth::user()->role_id > 2) {
                $lims_warehouse_list = Warehouse::where([
                    ['is_active', true],
                    ['id', Auth::user()->warehouse_id]
                ])->get();
            }
            else {
                $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            }
            $lims_tax_list = Tax::where('is_active', true)->get();
            $lims_product_list_without_variant = $this->productWithoutVariant();
            $lims_product_list_with_variant = $this->productWithVariant();
            $currency_list = Currency::where('is_active', true)->get();
            $custom_fields = CustomField::where('belongs_to', 'purchase')->get();
            $lims_account_list = Account::select('id', 'name', 'account_no','total_balance', 'is_default')->where('is_active', true)->get();
            return view('backend.purchase.create', compact('lims_supplier_list', 'lims_warehouse_list', 'lims_tax_list', 'lims_product_list_without_variant', 'lims_product_list_with_variant', 'currency_list', 'custom_fields', 'lims_account_list'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function store(Request $request)
    {
        if(isset($request->reference_no)) {
            $this->validate($request, [
                'reference_no' => [
                    'max:191', 'required', 'unique:purchases'
                ],
            ]);
        }

        DB::beginTransaction();

        try {
            $data = $request->except('document');
            $data['user_id'] = Auth::id();

            if(!isset($data['reference_no']))
            {
                $numberReservation = $this->reserveNumber('purchase', isset($data['created_at']) ? normalize_to_sql_datetime($data['created_at']) : null);
                $data['reference_no'] = $numberReservation->formatted_number;
            }

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
                    $document->move(public_path('documents/purchase'), $documentName);
                }
                else {
                    $documentName = $this->getTenantId() . '_' . $documentName . '.' . $ext;
                    $document->move(public_path('documents/purchase'), $documentName);
                }
                $data['document'] = $documentName;
            }

            if (isset($data['created_at'])) {
                $data['created_at'] = normalize_to_sql_datetime($data['created_at']);
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
            }

            $data['paid_amount'] = 0; // important as paid amount will be updated by PaymentService

            // return dd($data);
            $lims_purchase_data = Purchase::create($data);
            if (isset($numberReservation)) {
                $this->assignNumber($numberReservation, $lims_purchase_data);
            }
            if (!empty($request->goods_received_note_id) && class_exists(\App\Models\GoodsReceivedNote::class)) {
                \App\Models\GoodsReceivedNote::where('id', $request->goods_received_note_id)->update([
                    'status' => 'converted_to_purchase',
                    'purchase_id' => $lims_purchase_data->id,
                ]);
            }
            //inserting data for custom fields
            $custom_field_data = [];
            $custom_fields = CustomField::where('belongs_to', 'purchase')->select('name', 'type')->get();
            foreach ($custom_fields as $type => $custom_field) {
                $field_name = str_replace(' ', '_', strtolower($custom_field->name));
                if(isset($data[$field_name])) {
                    if($custom_field->type == 'checkbox' || $custom_field->type == 'multi_select')
                        $custom_field_data[$field_name] = implode(",", $data[$field_name]);
                    else
                        $custom_field_data[$field_name] = $data[$field_name];
                }
            }
            if(count($custom_field_data))
                DB::table('purchases')->where('id', $lims_purchase_data->id)->update($custom_field_data);
            $product_id = $data['product_id'] ?? [];
            $product_code = $data['product_code'] ?? [];
            $qty = $data['qty'] ?? [];
            $recieved = $data['recieved'] ?? $qty;
            $batch_no = $data['batch_no'] ?? array_fill(0, count($product_id), null);
            $expired_date = $data['expired_date'] ?? array_fill(0, count($product_id), null);
            $purchase_unit = $data['purchase_unit'] ?? array_fill(0, count($product_id), 'Unit');
            $net_unit_cost = $data['net_unit_cost'] ?? [];
            $unit_cost = $data['unit_cost'] ?? $net_unit_cost;
            $net_unit_margin = $data['net_unit_margin'] ?? array_fill(0, count($product_id), 0);
            $net_unit_margin_type = $data['net_unit_margin_type'] ?? array_fill(0, count($product_id), 'percentage');
            $net_unit_price = $data['net_unit_price'] ?? $net_unit_cost;
            $discount = $data['discount'] ?? array_fill(0, count($product_id), 0);
            $tax_rate = $data['tax_rate'] ?? array_fill(0, count($product_id), 0);
            $tax = $data['tax'] ?? array_fill(0, count($product_id), 0);
            $total = $data['subtotal'] ?? [];
            $imei_numbers = $data['imei_number'] ?? array_fill(0, count($product_id), null);
            $product_purchase = [];
            $log_data['item_description'] = '';

            $warehousePrices = [];
            foreach ($product_id as $i => $id) {
                $uName = $purchase_unit[$i] ?? 'Unit';
                $lims_purchase_unit_data = Unit::where('unit_name', $uName)->orWhere('unit_code', $uName)->first();
                if (!$lims_purchase_unit_data) {
                    $lims_purchase_unit_data = Unit::first();
                }
                $lims_product_data = Product::find($id);
                if (!$lims_product_data && !empty($data['product_name_text'][$i])) {
                    $manualName = trim($data['product_name_text'][$i]);
                    $lims_product_data = Product::firstOrCreate(
                        ['name' => $manualName],
                        [
                            'code' => 'ITM-' . mt_rand(100000, 999999),
                            'type' => 'standard',
                            'barcode_symbology' => 'code128',
                            'unit_id' => $lims_purchase_unit_data->id ?? 1,
                            'purchase_unit_id' => $lims_purchase_unit_data->id ?? 1,
                            'sale_unit_id' => $lims_purchase_unit_data->id ?? 1,
                            'cost' => $net_unit_cost[$i] ?? 0,
                            'price' => $net_unit_cost[$i] ?? 0,
                            'is_active' => true,
                        ]
                    );
                    $id = $lims_product_data->id;
                    $product_id[$i] = $id;
                }
                if (!$lims_product_data) continue;
                $price = $lims_product_data->price;
                //dealing with product batch: only its identity here, the stock movement posts the quantity
                if(isset($batch_no[$i])) {
                    $product_batch_data = ProductBatch::where([
                                            ['product_id', $lims_product_data->id],
                                            ['batch_no', $batch_no[$i]]
                                        ])->first();
                    if($product_batch_data) {
                        $product_batch_data->expired_date = $expired_date[$i];
                        $product_batch_data->save();
                    }
                    else {
                        $product_batch_data = ProductBatch::create([
                                                'product_id' => $lims_product_data->id,
                                                'batch_no' => $batch_no[$i],
                                                'expired_date' => $expired_date[$i],
                                                'qty' => 0
                                            ]);
                    }
                    $product_purchase['product_batch_id'] = $product_batch_data->id;
                }
                else
                    $product_purchase['product_batch_id'] = null;

                if($lims_product_data->is_variant) {
                    $lims_product_variant_data = ProductVariant::select('id', 'variant_id')->FindExactProductWithCode($lims_product_data->id, $product_code[$i])->first();
                    $product_purchase['variant_id'] = $lims_product_variant_data->variant_id;
                }
                else {
                    $product_purchase['variant_id'] = null;
                    if($product_purchase['product_batch_id']) {
                        //checking for price
                        $lims_product_warehouse_data = Product_Warehouse::where([
                                                        ['product_id', $id],
                                                        ['warehouse_id', $data['warehouse_id'] ],
                                                    ])
                                                    ->whereNotNull('price')
                                                    ->select('price')
                                                    ->first();
                        $price = $lims_product_warehouse_data ? $lims_product_warehouse_data->price : null;
                    }
                }
                if($price) {
                    $warehousePrices[] = [$id, $product_purchase['variant_id'], $product_purchase['product_batch_id'], $price];
                }
                // update cost, profit margin, and price
                $lims_product_data->cost = $unit_cost[$i] ?? $lims_product_data->cost;
                $lims_product_data->profit_margin = $net_unit_margin[$i] ?? $lims_product_data->profit_margin;
                $lims_product_data->profit_margin_type = $net_unit_margin_type[$i] ?? $lims_product_data->profit_margin_type;

                $lims_product_data->price = $net_unit_price[$i] ?? $lims_product_data->price;

                $lims_product_data->save();

                if(!empty($imei_numbers[$i])) {
                    // prevent duplication
                    $imeis = explode(',', $imei_numbers[$i]);
                    $imeis = array_map('trim', $imeis);
                    if (count($imeis) !== count(array_unique($imeis))) {
                        DB::rollBack();
                        return redirect('purchases/create')->with('not_permitted', __('db.Duplicate IMEI not allowed!'));
                    }
                    foreach ($imeis as $imei) {
                        if ($this->isImeiExist($imei, $id)) {
                            DB::rollBack();
                            return redirect('purchases/create')->with('not_permitted', __('db.Duplicate IMEI not allowed!'));
                        }
                    }
                }

                $log_data['item_description'] .= $lims_product_data->name. '-'. $qty[$i].' '.$lims_purchase_unit_data->unit_code.'<br>';

                $product_purchase['purchase_id'] = $lims_purchase_data->id;
                $product_purchase['product_id'] = $id;
                $product_purchase['imei_number'] = $imei_numbers[$i];
                $product_purchase['qty'] = $qty[$i];
                $product_purchase['recieved'] = $recieved[$i];
                $product_purchase['purchase_unit_id'] = $lims_purchase_unit_data->id;
                $product_purchase['net_unit_cost'] = $net_unit_cost[$i];
                $product_purchase['net_unit_margin'] = $net_unit_margin[$i];
                $product_purchase['net_unit_margin_type'] = $net_unit_margin_type[$i];
                $product_purchase['net_unit_price'] = $net_unit_price[$i];
                $product_purchase['discount'] = $discount[$i];
                $product_purchase['tax_rate'] = $tax_rate[$i];
                $product_purchase['tax'] = $tax[$i];
                $product_purchase['total'] = $total[$i];
                ProductPurchase::create($product_purchase);
            }
            $this->postPurchaseStock($lims_purchase_data, $warehousePrices);

            if ($data['payment_status'] == 3 || $data['payment_status'] == 4) {
                if (isset($data['payment_at'])) {
                    $data['payment_at'] = normalize_to_sql_datetime($data['payment_at']);
                } else {
                    $data['payment_at'] = date('Y-m-d H:i:s');
                }
                $pay_data = [
                    'paying_amount' => array_sum($data['paying_amount']),
                    'amount' => $data['payment_status'] == 1 ? 0 : array_sum($data['amount']),
                    'paid_by_id' => $data['paid_by_id'][0],
                    'cheque_no' => $data['cheque_no'],
                    'account_id' => $data['account_id'],
                    'payment_note' => $data['payment_note'],
                    'purchase_id' => $lims_purchase_data->id,

                    'currency_id' => $lims_purchase_data->currency_id,
                    'exchange_rate' => $lims_purchase_data->exchange_rate ?? 1,

                    'payment_at' => $data['payment_at']
                ];



                $response = (new PaymentService())->payForPurchase($pay_data);

                if (!$response['status']) {
                    DB::rollback();
                    throw new \Exception($response['message']);
                }
            }

            //creating log
            $log_data['action'] = 'Purchase Created';
            $log_data['user_id'] = Auth::id();
            $log_data['reference_no'] = $lims_purchase_data->reference_no;
            $log_data['date'] = $lims_purchase_data->created_at->toDateString();
            // $log_data['admin_email'] = config('admin_email');
            $log_data['admin_message'] = Auth::user()->name . ' has created a purchase. Reference No: ' .$lims_purchase_data->reference_no;
            $log_data['user_email'] = Auth::user()->email;
            $log_data['user_name'] = Auth::user()->name;
            $log_data['user_message'] = 'You just created a purchase. Reference No: ' .$lims_purchase_data->reference_no;
            // $log_data['mail_setting'] = MailSetting::latest()->first();
            $this->createActivityLog($log_data);

            DB::commit();

            return redirect('purchases')->with('message', __('db.Purchase created successfully'));
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect('purchases/create')->with('not_permitted', 'Transaction failed: ' . $e->getMessage());
        }
    }

    public function purchaseByCsv()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('purchases-add')){
            $lims_supplier_list = Supplier::where('is_active', true)->get();
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            $lims_tax_list = Tax::where('is_active', true)->get();

            return view('backend.purchase.import', compact('lims_supplier_list', 'lims_warehouse_list', 'lims_tax_list'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function importPurchase(Request $request)
    {
        DB::beginTransaction();

        try {
            // return dd($request->all());
            //get the file
            $upload=$request->file('file');
            $ext = pathinfo($upload->getClientOriginalName(), PATHINFO_EXTENSION);
            //checking if this is a CSV file
            if($ext != 'csv')
                return redirect()->back()->with('message', __('db.Please upload a CSV file'));

            $filePath=$upload->getRealPath();
            $file_handle = fopen($filePath, 'r');
            $i = 0;

            $qty = [];
            $tax = [];
            $discount = [];
            $counter = 1;
            //validate the file
            while (!feof($file_handle) ) {
                $current_line = fgetcsv($file_handle);
                if($current_line && $i > 0){
                    // return dd($current_line);
                    $product_data[] = Product::where([
                                        ['code', $current_line[0]],
                                        ['is_active', true]
                                    ])->first();
                    if(!$product_data[$i-1]) {
                        throw new \Exception('Product with this code '.$current_line[0].' does not exist!');
                        // return redirect()->back()->with('message', 'Product with this code '.$current_line[0].' does not exist!');
                    }
                    $unit[] = Unit::where('unit_code', $current_line[2])->first();
                    if(!$unit[$i-1]) {
                        throw new \Exception(__('db.Purchase unit does not exist!'));
                        // return redirect()->back()->with('message', __('db.Purchase unit does not exist!'));
                    }
                    if(strtolower($current_line[5]) != "no tax"){
                        $tax[] = Tax::where('name', $current_line[5])->first();
                        if(!$tax[$i-1]) {
                            throw new \Exception(__('db.Tax name does not exist!'));
                            // return redirect()->back()->with('message', __('db.Tax name does not exist!'));
                        }
                    }
                    else
                        $tax[$i-1]['rate'] = 0;

                    $qty[] = $current_line[1];
                    $cost[] = $current_line[3];
                    $discount[] = $current_line[4];
                    if (isset($current_line[6]) && $product_data[$i-1]->is_imei) {
                        $product_data[$i-1]->imei_number = $current_line[6];
                    }
                    $counter++;
                }
                $i++;
            }
            // return dd($product_data, 'hello');

            $data = $request->except('file');
            if(isset($data['created_at'])) {
                $dateNow = str_replace("/","-",$data['created_at']);
                $data['created_at'] = date("Y-m-d H:i:s", strtotime($dateNow));
                $data['updated_at'] = date("Y-m-d H:i:s", strtotime($dateNow));
            }
            else {
                $data['created_at'] = date("Y-m-d H:i:s");
                $data['updated_at'] = date("Y-m-d H:i:s");
            }
            if(!isset($data['reference_no']))
            {
                $numberReservation = $this->reserveNumber('purchase', $data['created_at']);
                $data['reference_no'] = $numberReservation->formatted_number;
            }

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
                if ($v->fails()) {
                    throw new \Exception($v->errors());
                    // return redirect()->back()->withErrors($v->errors());
                }

                $ext = pathinfo($document->getClientOriginalName(), PATHINFO_EXTENSION);
                $documentName = date("Ymdhis");
                if(!config('database.connections.zoloerp_landlord')) {
                    $documentName = $documentName . '.' . $ext;
                    $document->move(public_path('documents/purchase'), $documentName);
                }
                else {
                    $documentName = $this->getTenantId() . '_' . $documentName . '.' . $ext;
                    $document->move(public_path('documents/purchase'), $documentName);
                }
                $data['document'] = $documentName;
            }
            $item = 0;
            $grand_total = $data['shipping_cost'];
            $data['user_id'] = Auth::id();
            $lims_purchase_data = Purchase::create($data);
            if (isset($numberReservation)) {
                $this->assignNumber($numberReservation, $lims_purchase_data);
            }

            $counter = 1;
            foreach ($product_data as $key => $product) {
                if(isset($product->imei_number)) {
                    // prevent duplication
                    if ($this->isImeiExist($product->imei_number, $product->id)) {
                        throw new \Exception(__('db.Duplicate IMEI not allowed!'));
                        // return redirect('purchases/purchase_by_csv')->with('not_permitted', __('db.Duplicate IMEI not allowed!'));
                    }
                }
                $qty[$key] = (int) str_replace(",", "", $qty[$key]);
                $cost[$key] = (float) str_replace(",", "", $cost[$key]);
                $discount[$key] = (float) str_replace(",", "", $discount[$key]);
                if($product['tax_method'] == 1){
                    // return dd($cost);
                    $net_unit_cost = $cost[$key] - $discount[$key];
                    $product_tax = $net_unit_cost * ($tax[$key]['rate'] / 100) * $qty[$key];
                    $total = ($net_unit_cost * $qty[$key]) + $product_tax;
                }
                elseif($product['tax_method'] == 2){
                    $net_unit_cost = (100 / (100 + $tax[$key]['rate'])) * ($cost[$key] - $discount[$key]);
                    $product_tax = ($cost[$key] - $discount[$key] - $net_unit_cost) * $qty[$key];
                    $total = ($cost[$key] - $discount[$key]) * $qty[$key];
                }

                $product_purchase = new ProductPurchase();
                $product_purchase->purchase_id = $lims_purchase_data->id;
                $product_purchase->product_id = $product['id'];
                $product_purchase->qty = $qty[$key];
                if($data['status'] == 1)
                    $product_purchase->recieved = $qty[$key];
                else
                    $product_purchase->recieved = 0;
                $product_purchase->purchase_unit_id = $unit[$key]['id'];
                $product_purchase->net_unit_cost = number_format((float)$net_unit_cost, config('decimal'), '.', '');
                $product_purchase->discount = $discount[$key] * $qty[$key];
                $product_purchase->tax_rate = $tax[$key]['rate'];
                $product_purchase->tax = number_format((float)$product_tax, config('decimal'), '.', '');
                $product_purchase->total = number_format((float)$total, config('decimal'), '.', '');
                if (isset($product->imei_number)) {
                    if (empty($product_purchase->imei_number)) {
                        $product_purchase->imei_number = $product->imei_number;
                    } else {
                        $product_purchase->imei_number .= ',' . $product->imei_number;
                    }
                }
                $product_purchase->save();
                $lims_purchase_data->total_qty += $qty[$key];
                $lims_purchase_data->total_discount += $discount[$key] * $qty[$key];
                $lims_purchase_data->total_tax += number_format((float)$product_tax, config('decimal'), '.', '');
                $lims_purchase_data->total_cost += number_format((float)$total, config('decimal'), '.', '');
                $counter++;
            }
            $lims_purchase_data->item = $key + 1;
            $lims_purchase_data->order_tax = ($lims_purchase_data->total_cost - $lims_purchase_data->order_discount) * ($data['order_tax_rate'] / 100);
            $lims_purchase_data->grand_total = ($lims_purchase_data->total_cost + $lims_purchase_data->order_tax + $lims_purchase_data->shipping_cost) - $lims_purchase_data->order_discount;
            $lims_purchase_data->save();
            $this->postPurchaseStock($lims_purchase_data);

            DB::commit();
            return redirect('purchases');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect('purchases/purchase_by_csv')->with('not_permitted', "Error in row $counter: " . $e->getMessage());
        }
    }

    public function purchaseData(Request $request)
    {
        $general_setting = GeneralSetting::select('show_products_details_in_purchase_table')->first();
        
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
            6 => 'grand_total',
            8 => 'paid_amount',
        );
        if ($general_setting->show_products_details_in_purchase_table) {
            $columns = array(
                1 => 'created_at',
                2 => 'reference_no',
                8 => 'grand_total',
                10 => 'paid_amount',
            );
        } 

        $warehouse_id = $request->input('warehouse_id');
        $purchase_status = $request->input('purchase_status');
        $payment_status = $request->input('payment_status');

        $q = Purchase::whereDate('created_at', '>=' ,$request->input('starting_date'))->whereDate('created_at', '<=' ,$request->input('ending_date'));
        //check staff access
        $this->staffAccessCheck($q);
        if($warehouse_id)
            $q = $q->where('warehouse_id', $warehouse_id);
        if($purchase_status)
            $q = $q->where('status', $purchase_status);
        if($payment_status)
            $q = $q->withLegacyPaymentStatus((int) $payment_status);

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'purchases.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        //fetching custom fields data
        $custom_fields = CustomField::where([
                        ['belongs_to', 'purchase'],
                        ['is_table', true]
                    ])->pluck('name');
        $field_names = [];
        foreach($custom_fields as $fieldName) {
            $field_names[] = str_replace(" ", "_", strtolower($fieldName));
        }
        if(empty($request->input('search.value'))) {
            $q = Purchase::with('supplier', 'warehouse','products')
                ->whereDate('created_at', '>=' ,$request->input('starting_date'))
                ->whereDate('created_at', '<=' ,$request->input('ending_date'))
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir);
            //check staff access
            $this->staffAccessCheck($q);
            if($warehouse_id)
                $q = $q->where('warehouse_id', $warehouse_id);
            if($purchase_status)
                $q = $q->where('status', $purchase_status);
            if($payment_status)
                $q = $q->withLegacyPaymentStatus((int) $payment_status);
            $purchases = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $searchDate = date('Y-m-d', strtotime(str_replace('/', '-', $search)));

            $q = Purchase::query()
                ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')
                ->leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
                ->leftJoin('products', 'product_purchases.product_id', '=', 'products.id')
                ->whereNull('purchases.deleted_at')
                ->whereBetween(DB::raw('DATE(purchases.created_at)'), [
                    $request->input('starting_date'),
                    $request->input('ending_date')
                ]);

            // ✅ APPLY FILTERS FIRST (DO NOT MOVE THESE)
            if ($warehouse_id) {
                $q->where('purchases.warehouse_id', $warehouse_id);
            }

            if ($purchase_status) {
                $q->where('purchases.status', $purchase_status);
            }

            if ($payment_status) {
                $q->withLegacyPaymentStatus((int) $payment_status);
            }

            // ✅ ACCESS CONTROL
            if (Auth::user()->role_id > 2) {
                if (config('staff_access') == 'own') {
                    $q->where('purchases.user_id', Auth::id());
                } elseif (config('staff_access') == 'warehouse') {
                    $q->where('purchases.warehouse_id', Auth::user()->warehouse_id);
                }
            }

            // ✅ SAFE SEARCH GROUP
            $q->where(function ($query) use ($search, $searchDate, $field_names) {

                if (strtotime($searchDate)) {
                    $query->orWhereDate('purchases.created_at', $searchDate);
                }

                $query->orWhere('purchases.reference_no', 'LIKE', "%{$search}%")
                    ->orWhere('suppliers.name', 'LIKE', "%{$search}%")
                    ->orWhere('product_purchases.imei_number', 'LIKE', "%{$search}%")
                    ->orWhere('products.name', 'LIKE', "%{$search}%");

                foreach ($field_names as $field_name) {
                    $query->orWhere('purchases.' . $field_name, 'LIKE', "%{$search}%");
                }

            });

            // ✅ COUNT
            $totalFiltered = $q->distinct('purchases.id')->count('purchases.id');

            // ✅ SORTING
            $q->orderBy($order, $dir);

            // ✅ FETCH
            $purchases = $q->select('purchases.*')
                        ->groupBy('purchases.id')
                        ->skip($start)
                        ->take($limit)
                        ->get();
        }

        $data = array();
        if(!empty($purchases))
        {
            foreach ($purchases as $key=>$purchase)
            {
                $user = $purchase->user;

                $nestedData['id'] = $purchase->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($purchase->created_at->toDateString()));
                $nestedData['reference_no'] = $purchase->reference_no;
                $nestedData['created_by'] = $user->name ?? 'N/A';

                if($purchase->supplier_id) {
                    $supplier = $purchase->supplier;
                }
                else {
                    $supplier = new Supplier();
                }

                // product details and qty
                $productNames = [];
                $productQtys = [];
                $total_products = $purchase->products->count();
                foreach ($purchase->products as $key => $product) {
                    if( $key + 1 < $total_products){
                $productNames[] = '<div style="border-bottom: 1px solid #ccc; padding-bottom: 4px; margin-bottom: 4px;">' . e($product->name) . '</div>';
                    }else{
                        $productNames[] = '<div style=" padding-bottom: 4px; margin-bottom: 4px;">' . e($product->name) . '</div>';
                    }

                    $productQtys[] = '<div style="padding-bottom: 4px; margin-bottom: 4px;"><span class="badge badge-primary">' . e($product->pivot->qty) . '</span></div>';
                }

                $nestedData['supplier'] = $purchase->supplier->name ?? '';  // supplier name safely
                $nestedData['products'] = implode('', $productNames);      // no commas, just join directly
                $nestedData['products_qty'] = implode('', $productQtys);

                if ($purchase->status == 1) {
                    $nestedData['purchase_status'] = '<div class="badge badge-success">' . __('db.Recieved') . '</div>';
                    $purchase_status = __('db.Recieved');
                }
                elseif($purchase->status == 2){
                    $nestedData['purchase_status'] = '<div class="badge badge-success">'.__('db.Partial').'</div>';
                    $purchase_status = __('db.Partial');
                }
                elseif($purchase->status == 3){
                    $nestedData['purchase_status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                    $purchase_status = __('db.Pending');
                }
                else{
                    $nestedData['purchase_status'] = '<div class="badge badge-danger">'.__('db.Ordered').'</div>';
                    $purchase_status = __('db.Ordered');
                }

                if($purchase->paid_amount < $purchase->grand_total)
                    $nestedData['payment_status'] = '<div class="badge badge-danger">'.__('db.Due').'</div>';
                else
                    $nestedData['payment_status'] = '<div class="badge badge-success">'.__('db.Paid').'</div>';

                if(!$purchase->exchange_rate || $purchase->exchange_rate == 0)
                    $purchase->exchange_rate = 1;

                $nestedData['grand_total'] = number_format($purchase->grand_total / $purchase->exchange_rate, config('decimal'));
                $returned_amount = DB::table('return_purchases')->where('purchase_id', $purchase->id)->sum('grand_total');
                $nestedData['returned_amount'] = number_format($returned_amount / $purchase->exchange_rate, config('decimal'));
                $nestedData['paid_amount'] = number_format($purchase->paid_amount / $purchase->exchange_rate, config('decimal'));
                $nestedData['due'] = number_format(
                    max(0, ($purchase->grand_total - $returned_amount - $purchase->paid_amount) / $purchase->exchange_rate),
                    config('decimal')
                );
                //fetching custom fields data
                foreach($field_names as $field_name) {
                    $nestedData[$field_name] = $purchase->$field_name;
                }
                $nestedData['options'] = '<div class="btn-group">
                            <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'.__("db.action").'
                              <span class="caret"></span>
                              <span class="sr-only">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" user="menu">
                                <li>
                                    <button type="button" class="btn btn-link view"><i class="fa fa-eye"></i> '.__('db.View').'</button>
                                </li>';
                if(in_array("purchases-add", $request['all_permission']))
                    $nestedData['options'] .= '<li>
                        <a href="'.route('purchase.duplicate', $purchase->id).'" class="btn btn-link"><i class="fa fa-copy"></i> '.__('db.Duplicate').'</a>
                        </li>';
                if(in_array("purchases-edit", $request['all_permission']))
                    $nestedData['options'] .= '<li>
                        <a href="'.route('purchases.edit', $purchase->id).'" class="btn btn-link"><i class="dripicons-document-edit"></i> '.__('db.edit').'</a>
                        </li>';
                if(in_array("purchase-payment-index", $request['all_permission']))
                    $nestedData['options'] .=
                        '<li>
                            <button type="button" class="get-payment btn btn-link" data-id = "'.$purchase->id.'"><i class="fa fa-money"></i> '.__('db.View Payment').'</button>
                        </li>';

                if(in_array("purchase-payment-add", $request['all_permission'])) {
                    $currency_code_name = $purchase->currency->code ?? 'USD';
                    $nestedData['options'] .=
                        '<li>
                            <button
                                type="button"
                                class="add-payment btn btn-link"
                                data-id="'.$purchase->id.'"
                                data-currency_id="'.$purchase->currency_id.'"
                                data-currency_name="'.$currency_code_name.'"
                                data-exchange_rate="'.$purchase->exchange_rate.'"
                                data-toggle="modal"
                                data-target="#add-payment">
                                <i class="fa fa-plus"></i> '.__('db.Add Payment').'
                            </button>
                        </li>';
                }
                if(in_array("purchases-delete", $request['all_permission']))
                    $nestedData['options'] .= \Form::open(["route" => ["purchases.destroy", $purchase->id], "method" => "DELETE"] ).'
                            <li>
                              <button type="submit" class="btn btn-link" onclick="return confirmDelete()"><i class="dripicons-trash"></i> '.__("db.delete").'</button>
                            </li>'.\Form::close().'
                        </ul>
                    </div>';

                // data for purchase details by one click                
                if($purchase->currency_id) {
                    $currency = Currency::select('code')->find($purchase->currency_id);
                    if($currency)
                        $currency_code = $currency->code;
                }
                else
                    $currency_code = 'N/A';

                $nestedData['purchase'] = array( '[ "'.date(config('date_format'), strtotime($purchase->created_at->toDateString())).'"', ' "'.$purchase->reference_no.'"', ' "'.$purchase_status.'"',  ' "'.$purchase->id.'"', ' "'.$purchase->warehouse->name.'"', ' "'.$purchase->warehouse->phone.'"', ' "'.preg_replace('/\s+/S', " ", $purchase->warehouse->address).'"', ' "'.$supplier->name.'"', ' "'.$supplier->company_name.'"', ' "'.$supplier->email.'"', ' "'.$supplier->phone_number.'"', ' "'.preg_replace('/\s+/S', " ", $supplier->address).'"', ' "'.$supplier->city.'"', ' "'.$purchase->total_tax.'"', ' "'.$purchase->total_discount.'"', ' "'.$purchase->total_cost.'"', ' "'.$purchase->order_tax.'"', ' "'.$purchase->order_tax_rate.'"', ' "'.$purchase->order_discount.'"', ' "'.$purchase->shipping_cost.'"', ' "'.$purchase->grand_total.'"', ' "'.$purchase->paid_amount.'"', ' "'.preg_replace('/\s+/S', " ", $purchase->note).'"', ' "'.$user->name.'"', ' "'.$user->email.'"', ' "'.$purchase->document.'"', ' "'.$currency_code.'"', ' "'.$purchase->exchange_rate.'"]'
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

    public function productPurchaseData($id)
    {
        try {
            $lims_product_purchase_data = ProductPurchase::where('purchase_id', $id)->get();
            $product_purchase = [];
            foreach ($lims_product_purchase_data as $key => $product_purchase_data) {
                $product = Product::find($product_purchase_data->product_id);
                $unit = Unit::find($product_purchase_data->purchase_unit_id);
                if($product_purchase_data->variant_id) {
                    $lims_product_variant_data = ProductVariant::FindExactProduct($product->id, $product_purchase_data->variant_id)->select('item_code')->first();
                    $product->code = $lims_product_variant_data->item_code;
                }
                if($product_purchase_data->product_batch_id) {
                    $product_batch_data = ProductBatch::select('batch_no')->find($product_purchase_data->product_batch_id);
                    $product_purchase[7][$key] = $product_batch_data->batch_no;
                }
                else
                    $product_purchase[7][$key] = 'N/A';
                $product_purchase[0][$key] = $product->name . ' [' . $product->code.']';
                $returned_imei_number_data = '';
                if($product_purchase_data->imei_number) {
                    $product_purchase[0][$key] .= '<br><span style="white-space: normal !important;word-break: break-word !important;overflow-wrap: anywhere !important;max-width: 100%;display: block;">IMEI or Serial Number: '. $product_purchase_data->imei_number.'</span>';
                    $returned_imei_number_data = DB::table('return_purchases')
                    ->join('purchase_product_return', 'return_purchases.id', '=', 'purchase_product_return.return_id')
                    ->where([
                        ['return_purchases.purchase_id', $id],
                        ['purchase_product_return.product_id', $product_purchase_data->product_id]
                    ])->select('purchase_product_return.imei_number')
                    ->first();
                }
                $product_purchase[1][$key] = $product_purchase_data->qty;
                $product_purchase[2][$key] = $unit->unit_code;
                $product_purchase[3][$key] = $product_purchase_data->tax;
                $product_purchase[4][$key] = $product_purchase_data->tax_rate;
                $product_purchase[5][$key] = $product_purchase_data->discount;
                $product_purchase[6][$key] = $product_purchase_data->total;
                if($returned_imei_number_data) {
                    $product_purchase[8][$key] = $product_purchase_data->return_qty.'<br><span style="white-space: normal !important;word-break: break-word !important;overflow-wrap: anywhere !important;max-width: 100%;display: block;">IMEI or Serial Number: '. $returned_imei_number_data->imei_number .'</span>';
                }
                else
                    $product_purchase[8][$key] = $product_purchase_data->return_qty;
            }
            return $product_purchase;
        }
        catch (\Exception $e) {
            /*return response()->json('errors' => [$e->getMessage());*/
            //return response()->json(['errors' => [$e->getMessage()]], 422);
            return 'Something is wrong!';
        }

    }

    public function productWithoutVariant()
    {
        return Product::where('products.is_active', true)->whereIn('products.type', ['standard', 'service', 'digital'])
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->select('products.id', 'products.type', 'products.name', 'products.code', 'products.price', 'products.cost', 'products.tax_id', 'products.unit_id', 'units.unit_name', 'units.unit_code')
            ->where(fn ($query) => $query->whereNull('products.is_variant')->orWhere('products.is_variant', false))
            ->get();
    }

    public function productWithVariant()
    {
        return Product::join('product_variants', 'products.id', 'product_variants.product_id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->ActiveStandard()
            ->whereNotNull('is_variant')
            ->select('products.id', 'products.name', 'product_variants.item_code', 'products.code', 'products.price', 'product_variants.additional_price', 'products.cost', 'product_variants.additional_cost', 'products.tax_id', 'products.unit_id', 'units.unit_name', 'units.unit_code')
            ->orderBy('position')
            ->get();
    }

    public function newProductWithVariant()
    {
        return Product::ActiveStandard()
                ->whereNotNull('is_variant')
                ->whereNotNull('variant_data')
                ->select('id', 'name', 'variant_data')
                ->get();
    }

    public function limsProductSearch(Request $request)
    {
        // dd($request->all());
        $product_code = explode("|", $request['data']);
        $product_code[0] = rtrim($product_code[0], " ");
        $lims_product_data = Product::where([
                                ['code', $product_code[0]],
                                ['is_active', true]
                            ])
                            ->whereNull('is_variant')
                            ->first();
        if(!$lims_product_data) {
            $lims_product_data = Product::where([
                                ['name', $product_code[1]],
                                ['is_active', true]
                            ])
                            ->whereNotNull(['is_variant'])
                            ->first();
            $lims_product_data = Product::join('product_variants', 'products.id', 'product_variants.product_id')
                ->where([
                    ['product_variants.item_code', $product_code[0]],
                    ['products.is_active', true]
                ])
                ->whereNotNull('is_variant')
                ->select('products.*', 'product_variants.item_code', 'product_variants.additional_cost')
                ->first();
            $lims_product_data->cost += $lims_product_data->additional_cost;
        }
        $product[] = $lims_product_data->name;
        if($lims_product_data->is_variant)
            $product[] = $lims_product_data->item_code;
        else
            $product[] = $lims_product_data->code;

        $product[] = $lims_product_data->cost;
        $product['profit_margin'] = $lims_product_data->profit_margin;
        $product['profit_margin_type'] = $lims_product_data->profit_margin_type;
        $product['product_price'] = $lims_product_data->price;

        $cost = (float)$lims_product_data->cost;
        $price = (float)$lims_product_data->price;
        
        if ($cost > 0 && $lims_product_data->profit_margin_type === 'percentage') {
            $calculatedMargin = (($price - $cost) / $cost) * 100;
        } else if ($cost > 0 && $lims_product_data->profit_margin_type === 'flat') {
            $calculatedMargin = $price - $cost;
        } else {
            $calculatedMargin = 0; // or null, or skip updating
        }
        
        if (round($calculatedMargin, 2) != round((float)$lims_product_data->profit_margin, 2)) {
            $product['profit_margin'] = $calculatedMargin;
        }

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
        $product[] = $lims_product_data->is_batch;
        $product[] = $lims_product_data->is_imei;
        // return dd($product);
        return $product;
    }

    public function edit($id)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('purchases-edit')){
            $lims_supplier_list = Supplier::where('is_active', true)->get();
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            $lims_tax_list = Tax::where('is_active', true)->get();
            $lims_product_list_without_variant = $this->productWithoutVariant();
            $lims_product_list_with_variant = $this->productWithVariant();
            $lims_purchase_data = Purchase::find($id);
            $lims_product_purchase_data = ProductPurchase::where('purchase_id', $id)->get();
            foreach ($lims_product_purchase_data as $purchase) {
                $lims_product_data = Product::select('cost', 'profit_margin', 'profit_margin_type', 'price')->where('id', $purchase->product_id)->first();
                $cost = (float) $purchase->net_unit_cost;
                if ($lims_product_data) {
                    $price = (float) $purchase->net_unit_price == 0 ? $lims_product_data->price : $purchase->net_unit_price;
                } else {
                    $price = (float) $purchase->net_unit_price;
                }
                $margin = (float) $purchase->net_unit_margin;
                $margin_type = $purchase->net_unit_margin_type;

                if ($cost > 0 && $price > 0 && $margin_type === 'percentage') {
                    $calculatedMargin = (($price - $cost) / $cost) * 100;

                    if (round($calculatedMargin, 2) != round($margin, 2)) {
                        $purchase->net_unit_margin = $calculatedMargin;
                        $purchase->net_unit_price = $price;
                        $purchase->save();
                    }
                }
            }
            $currency_list = Currency::where('is_active', true)->get();
            if($lims_purchase_data->exchange_rate)
                $currency_exchange_rate = $lims_purchase_data->exchange_rate;
            else
                $currency_exchange_rate = 1;
            $custom_fields = CustomField::where('belongs_to', 'purchase')->get();
            return view('backend.purchase.edit', compact('lims_warehouse_list', 'lims_supplier_list', 'lims_product_list_without_variant', 'lims_product_list_with_variant', 'lims_tax_list', 'lims_purchase_data', 'lims_product_purchase_data', 'currency_list', 'currency_exchange_rate', 'custom_fields'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));

    }

    public function update(UpdatePurchaseRequest $request, $id)
    {
        $lims_purchase_data = Purchase::find($id);
        $data = $request->except('document');
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

            $this->fileDelete(public_path('documents/purchase/'), $lims_purchase_data->document);

            $ext = pathinfo($document->getClientOriginalName(), PATHINFO_EXTENSION);
            $documentName = date("Ymdhis");
            if(!config('database.connections.zoloerp_landlord')) {
                $documentName = $documentName . '.' . $ext;
                $document->move(public_path('documents/purchase'), $documentName);
            }
            else {
                $documentName = $this->getTenantId() . '_' . $documentName . '.' . $ext;
                $document->move(public_path('documents/purchase'), $documentName);
            }
            $data['document'] = $documentName;
        }
        //return dd($data);
        DB::beginTransaction();

        try {
            $lims_purchase_data = Purchase::whereKey($id)->lockForUpdate()->firstOrFail();
            $balance = (float)$data['grand_total'] - (float)$data['paid_amount'];
            if ($balance < 0 || $balance > 0) {
                $data['payment_status'] = 1;
            } else {
                $data['payment_status'] = 2;
            }
            $lims_product_purchase_data = ProductPurchase::where('purchase_id', $id)->get();

            $data['created_at'] = date("Y-m-d", strtotime(str_replace("/", "-", $data['created_at']))) . ' '. date("H:i:s");
            $product_id = $data['product_id'] ?? [];
            $product_code = $data['product_code'] ?? [];
            $qty = $data['qty'] ?? [];
            $recieved = $data['recieved'] ?? $qty;
            $batch_no = $data['batch_no'] ?? array_fill(0, count($product_id), null);
            $expired_date = $data['expired_date'] ?? array_fill(0, count($product_id), null);
            $purchase_unit = $data['purchase_unit'] ?? array_fill(0, count($product_id), 'Unit');
            $net_unit_cost = $data['net_unit_cost'] ?? [];
            $unit_cost = $data['unit_cost'] ?? $net_unit_cost;
            $net_unit_margin = $data['net_unit_margin'] ?? array_fill(0, count($product_id), 0);
            $net_unit_margin_type = $data['net_unit_margin_type'] ?? array_fill(0, count($product_id), 'percentage');
            $net_unit_price = $data['net_unit_price'] ?? $net_unit_cost;
            $discount = $data['discount'] ?? array_fill(0, count($product_id), 0);
            $tax_rate = $data['tax_rate'] ?? array_fill(0, count($product_id), 0);
            $tax = $data['tax'] ?? array_fill(0, count($product_id), 0);
            $total = $data['subtotal'] ?? [];
            $imei_number = $new_imei_number = $data['imei_number'] ?? array_fill(0, count($product_id), null);
            $product_purchase = [];

            // Return what the purchase received before its lines change; the new lines are received after the update.
            $this->reversePurchaseStock($lims_purchase_data);
            foreach ($lims_product_purchase_data as $i => $product_purchase_data) {
                $lims_product_data = Product::find($product_purchase_data->product_id);
                if ($lims_product_data) {
                    // update cost, profit margin, and price
                    $lims_product_data->cost = $unit_cost[$i] ?? $lims_product_data->cost;
                    $lims_product_data->profit_margin = $net_unit_margin[$i] ?? $lims_product_data->profit_margin;
                    $lims_product_data->profit_margin_type = $net_unit_margin_type[$i] ?? $lims_product_data->profit_margin_type;
                    $lims_product_data->price = $net_unit_price[$i] ?? $lims_product_data->price;
                    $lims_product_data->save();
                }
                $product_purchase_data->delete();
            }

            $log_data['item_description'] = '';
            $warehousePrices = [];
            foreach ($product_id as $key => $pro_id) {
                $uName = $purchase_unit[$key] ?? 'Unit';
                $lims_purchase_unit_data = Unit::where('unit_name', $uName)->orWhere('unit_code', $uName)->first() ?? Unit::first();
                $lims_product_data = Product::find($pro_id);
                if (!$lims_product_data && !empty($data['product_name_text'][$key])) {
                    $manualName = trim($data['product_name_text'][$key]);
                    $lims_product_data = Product::firstOrCreate(
                        ['name' => $manualName],
                        [
                            'code' => 'ITM-' . mt_rand(100000, 999999),
                            'type' => 'standard',
                            'barcode_symbology' => 'code128',
                            'unit_id' => $lims_purchase_unit_data->id ?? 1,
                            'purchase_unit_id' => $lims_purchase_unit_data->id ?? 1,
                            'sale_unit_id' => $lims_purchase_unit_data->id ?? 1,
                            'cost' => $net_unit_cost[$key] ?? 0,
                            'price' => $net_unit_cost[$key] ?? 0,
                            'is_active' => true,
                        ]
                    );
                    $pro_id = $lims_product_data->id;
                    $product_id[$key] = $pro_id;
                }
                if (!$lims_product_data) continue;
                $price = null;
                //dealing with product batch: only its identity here, the stock movement posts the quantity
                if($batch_no[$key]) {
                    $product_batch_data = ProductBatch::where([
                                            ['product_id', $lims_product_data->id],
                                            ['batch_no', $batch_no[$key]]
                                        ])->first();
                    if($product_batch_data) {
                        $product_batch_data->expired_date = $expired_date[$key];
                        $product_batch_data->save();
                    }
                    else {
                        $product_batch_data = ProductBatch::create([
                                                'product_id' => $lims_product_data->id,
                                                'batch_no' => $batch_no[$key],
                                                'expired_date' => $expired_date[$key],
                                                'qty' => 0
                                            ]);
                    }
                    $product_purchase['product_batch_id'] = $product_batch_data->id;
                }
                else
                    $product_purchase['product_batch_id'] = null;

                if($lims_product_data->is_variant) {
                    $lims_product_variant_data = ProductVariant::select('id', 'variant_id')->FindExactProductWithCode($pro_id, $product_code[$key])->first();
                    $product_purchase['variant_id'] = $lims_product_variant_data->variant_id;
                }
                else {
                    $product_purchase['variant_id'] = null;
                    if($product_purchase['product_batch_id']) {
                        //checking for price
                        $lims_product_warehouse_data = Product_Warehouse::where([
                                                        ['product_id', $pro_id],
                                                        ['warehouse_id', $data['warehouse_id'] ],
                                                    ])
                                                    ->whereNotNull('price')
                                                    ->select('price')
                                                    ->first();
                        if($lims_product_warehouse_data)
                            $price = $lims_product_warehouse_data->price;
                    }
                }
                if($price) {
                    $warehousePrices[] = [$pro_id, $product_purchase['variant_id'], $product_purchase['product_batch_id'], $price];
                }
                //dealing with imei numbers
                if($new_imei_number[$key]) {
                    // prevent duplication
                    $imeis = explode(',', $new_imei_number[$key]);
                    $imeis = array_map('trim', $imeis);
                    if (count($imeis) !== count(array_unique($imeis))) {
                        DB::rollBack();
                        return redirect()->route('purchases.edit', $id)->with('not_permitted', __('db.Duplicate IMEI not allowed!'));
                    }
                    foreach ($imeis as $imei) {
                        if ($this->isImeiExist($imei, $pro_id)) {
                            DB::rollBack();
                            return redirect()->route('purchases.edit', $id)->with('not_permitted', __('db.Duplicate IMEI not allowed!'));
                        }
                    }
                }

                $log_data['item_description'] .= $lims_product_data->name. '-'. $qty[$key].' '.$lims_purchase_unit_data->unit_code.'<br>';

                $product_purchase['purchase_id'] = $id ;
                $product_purchase['product_id'] = $pro_id;
                $product_purchase['qty'] = $qty[$key];
                $product_purchase['recieved'] = $recieved[$key];
                $product_purchase['purchase_unit_id'] = $lims_purchase_unit_data->id;
                $product_purchase['net_unit_cost'] = $net_unit_cost[$key];
                $product_purchase['net_unit_margin'] = $net_unit_margin[$key];
                $product_purchase['net_unit_price'] = $net_unit_price[$key];
                $product_purchase['discount'] = $discount[$key];
                $product_purchase['tax_rate'] = $tax_rate[$key];
                $product_purchase['tax'] = $tax[$key];
                $product_purchase['total'] = $total[$key];
                $product_purchase['imei_number'] = $imei_number[$key] ?? null;
                ProductPurchase::create($product_purchase);
            }

            $lims_purchase_data->update($data);
            $this->postPurchaseStock($lims_purchase_data->refresh(), $warehousePrices);

            //creating log
            $log_data['action'] = 'Purchase Updated';
            $log_data['user_id'] = Auth::id();
            $log_data['reference_no'] = $lims_purchase_data->reference_no;
            $log_data['date'] = $lims_purchase_data->created_at->toDateString();
            // $log_data['admin_email'] = config('admin_email');
            $log_data['admin_message'] = Auth::user()->name . ' has updated a purchase. Reference No: ' .$lims_purchase_data->reference_no;
            $log_data['user_email'] = Auth::user()->email;
            $log_data['user_name'] = Auth::user()->name;
            $log_data['user_message'] = 'You just updated a purchase. Reference No: ' .$lims_purchase_data->reference_no;
            // $log_data['mail_setting'] = $mail_setting = MailSetting::latest()->first();
            $this->createActivityLog($log_data);

            //inserting data for custom fields
            $custom_field_data = [];
            $custom_fields = CustomField::where('belongs_to', 'purchase')->select('name', 'type')->get();
            foreach ($custom_fields as $type => $custom_field) {
                $field_name = str_replace(' ', '_', strtolower($custom_field->name));
                if(isset($data[$field_name])) {
                    if($custom_field->type == 'checkbox' || $custom_field->type == 'multi_select')
                        $custom_field_data[$field_name] = implode(",", $data[$field_name]);
                    else
                        $custom_field_data[$field_name] = $data[$field_name];
                }
            }
            if(count($custom_field_data))
                DB::table('purchases')->where('id', $lims_purchase_data->id)->update($custom_field_data);

            DB::commit();
            return redirect('purchases')->with('message', __('db.Purchase updated successfully'));
        } catch(\Exception $e) {
            DB::rollBack();
            return redirect()->route('purchases.edit', $id)->with('not_permitted', $e->getMessage());
        }
    }

    public function duplicate($id)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('purchases-add')){
            $lims_supplier_list = Supplier::where('is_active', true)->get();
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            $lims_tax_list = Tax::where('is_active', true)->get();
            $lims_product_list_without_variant = $this->productWithoutVariant();
            $lims_product_list_with_variant = $this->productWithVariant();
            $lims_purchase_data = Purchase::find($id);
            $lims_product_purchase_data = ProductPurchase::where('purchase_id', $id)->get();
            if($lims_purchase_data->exchange_rate)
                $currency_exchange_rate = $lims_purchase_data->exchange_rate;
            else
                $currency_exchange_rate = 1;
            $custom_fields = CustomField::where('belongs_to', 'purchase')->get();
            return view('backend.purchase.duplicate', compact('lims_warehouse_list', 'lims_supplier_list', 'lims_product_list_without_variant', 'lims_product_list_with_variant', 'lims_tax_list', 'lims_purchase_data', 'lims_product_purchase_data', 'currency_exchange_rate', 'custom_fields'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));

    }

    public function addPayment(Request $request)
    {
        $data = $request->except('_token');

        if (isset($data['payment_at'])) {
            $data['payment_at'] = normalize_to_sql_datetime($data['payment_at']);
        } else {
            $data['payment_at'] = date('Y-m-d H:i:s');
        }

        $response = (new PaymentService())->payForPurchase($data);

        if ($response['status']) {
            return redirect('purchases')->with('message', __('db.Payment created successfully'));
        }
        return redirect('purchases')->with('not_permitted', 'Payment failed!');
    }

    public function getPayment($id)
    {
        $lims_payment_list = Payment::where('purchase_id', $id)->get();
        $date = [];
        $payment_reference = [];
        $paid_amount = [];
        $paying_method = [];
        $payment_id = [];
        $payment_note = [];
        $cheque_no = [];
        $change = [];
        $paying_amount = [];
        $account_name = [];
        $account_id = [];
        $payment_at = [];
        foreach ($lims_payment_list as $payment) {
            // added currency for previously inserted data
            if (!$payment->currency_id) {
                $lims_purchase_data = Purchase::find($payment->purchase_id);

                if ($lims_purchase_data) {
                    $payment->currency_id = $lims_purchase_data->currency_id;
                    $payment->exchange_rate = $lims_purchase_data->exchange_rate ?? 1;
                }
            }

            $date[] = date(config('date_format'), strtotime($payment->created_at->toDateString())) . ' '. $payment->created_at->toTimeString();
            $payment_reference[] = $payment->payment_reference;
            $paid_amount[] = $payment->amount;
            $change[] = $payment->change;
            $paying_method[] = $payment->paying_method;
            $paying_amount[] = $payment->amount + $payment->change;
            if($payment->paying_method == 'Cheque'){
                $lims_payment_cheque_data = PaymentWithCheque::where('payment_id',$payment->id)->first();
                $cheque_no[] = $lims_payment_cheque_data->cheque_no;
            }
            else{
                $cheque_no[] = null;
            }
            $payment_id[] = $payment->id;
            $payment_note[] = $payment->payment_note;
            $lims_account_data = Account::find($payment->account_id);
            if($lims_account_data) {
                $account_name[] = $lims_account_data->name;
                $account_id[] = $lims_account_data->id;
            }
            else {
                $account_name[] = 'N/A';
                $account_id[] = 0;
            }

            $payment->payment_at = $payment->payment_at ?? $payment->created_at;
            $payment->save();
            $payment_at[] = date(config('date_format'), strtotime($payment->payment_at->toDateString()));
        }
        $payments[] = $date;
        $payments[] = $payment_reference;
        $payments[] = $paid_amount;
        $payments[] = $paying_method;
        $payments[] = $payment_id;
        $payments[] = $payment_note;
        $payments[] = $cheque_no;
        $payments[] = $change;
        $payments[] = $paying_amount;
        $payments[] = $account_name;
        $payments[] = $account_id;
        $payments[] = $payment_at;

        return $payments;
    }

    public function updatePayment(Request $request)
    {
        $data = $request->all();
        $lims_payment_data = Payment::find($data['payment_id']);
        $lims_purchase_data = Purchase::find($lims_payment_data->purchase_id);
        //updating purchase table
        $amount_dif = $lims_payment_data->amount - $data['edit_amount'];
        $lims_purchase_data->paid_amount = $lims_purchase_data->paid_amount - $amount_dif;
        $balance = $lims_purchase_data->grand_total - $lims_purchase_data->paid_amount;
        if($balance > 0 || $balance < 0)
            $lims_purchase_data->payment_status = 1;
        elseif ($balance == 0)
            $lims_purchase_data->payment_status = 2;
        $lims_purchase_data->save();

        if (isset($data['payment_at'])) {
            $data['payment_at'] = normalize_to_sql_datetime($data['payment_at']);
        } else {
            $data['payment_at'] = date('Y-m-d H:i:s');
        }

        //updating payment data
        $lims_payment_data->account_id = $data['account_id'];
        $lims_payment_data->amount = $data['edit_amount'];
        $lims_payment_data->change = $data['edit_paying_amount'] - $data['edit_amount'];
        $lims_payment_data->payment_note = $data['edit_payment_note'];
        $lims_payment_data->payment_at = $data['payment_at'];
        $lims_payment_data->currency_id = $lims_purchase_data->currency_id;
        $lims_payment_data->exchange_rate = $lims_purchase_data->exchange_rate ?? 1;
        $lims_pos_setting_data = PosSetting::latest()->first();
        if($data['edit_paid_by_id'] == 1)
            $lims_payment_data->paying_method = 'Cash';
        elseif ($data['edit_paid_by_id'] == 2)
            $lims_payment_data->paying_method = 'Gift Card';
        elseif ($data['edit_paid_by_id'] == 3 && $lims_pos_setting_data->stripe_secret_key) {
            \Stripe\Stripe::setApiKey($lims_pos_setting_data->stripe_secret_key);
            $token = $data['stripeToken'];
            $amount = $data['edit_amount'];
            if($lims_payment_data->paying_method == 'Credit Card'){
                $lims_payment_with_credit_card_data = PaymentWithCreditCard::where('payment_id', $lims_payment_data->id)->first();

                \Stripe\Refund::create(array(
                  "charge" => $lims_payment_with_credit_card_data->charge_id,
                ));

                $charge = \Stripe\Charge::create([
                    'amount' => $amount * 100,
                    'currency' => 'usd',
                    'source' => $token,
                ]);

                $lims_payment_with_credit_card_data->charge_id = $charge->id;
                $lims_payment_with_credit_card_data->save();
            }
            elseif($lims_pos_setting_data->stripe_secret_key) {
                // Charge the Customer
                $charge = \Stripe\Charge::create([
                    'amount' => $amount * 100,
                    'currency' => 'usd',
                    'source' => $token,
                ]);

                $data['charge_id'] = $charge->id;
                PaymentWithCreditCard::create($data);
            }
            $lims_payment_data->paying_method = 'Credit Card';
        }
        else{
            if($lims_payment_data->paying_method == 'Cheque'){
                $lims_payment_data->paying_method = 'Cheque';
                $lims_payment_cheque_data = PaymentWithCheque::where('payment_id', $data['payment_id'])->first();
                $lims_payment_cheque_data->cheque_no = $data['edit_cheque_no'];
                $lims_payment_cheque_data->save();
            }
            else{
                $lims_payment_data->paying_method = 'Cheque';
                $data['cheque_no'] = $data['edit_cheque_no'];
                PaymentWithCheque::create($data);
            }
        }
        $lims_payment_data->save();
        return redirect('purchases')->with('message', __('db.Payment updated successfully'));
    }

    public function deletePayment(Request $request)
    {
        $lims_payment_data = Payment::find($request['id']);
        $lims_purchase_data = Purchase::where('id', $lims_payment_data->purchase_id)->first();
        $lims_purchase_data->paid_amount -= $lims_payment_data->amount;
        $balance = $lims_purchase_data->grand_total - $lims_purchase_data->paid_amount;
        if($balance > 0 || $balance < 0)
            $lims_purchase_data->payment_status = 1;
        elseif ($balance == 0)
            $lims_purchase_data->payment_status = 2;
        $lims_purchase_data->save();
        $lims_pos_setting_data = PosSetting::latest()->first();

        if($lims_payment_data->paying_method == 'Credit Card' && $lims_pos_setting_data->stripe_secret_key) {
            $lims_payment_with_credit_card_data = PaymentWithCreditCard::where('payment_id', $request['id'])->first();
            \Stripe\Stripe::setApiKey($lims_pos_setting_data->stripe_secret_key);
            \Stripe\Refund::create(array(
              "charge" => $lims_payment_with_credit_card_data->charge_id,
            ));

            $lims_payment_with_credit_card_data->delete();
        }
        elseif ($lims_payment_data->paying_method == 'Cheque') {
            $lims_payment_cheque_data = PaymentWithCheque::where('payment_id', $request['id'])->first();
            $lims_payment_cheque_data->delete();
        }
        $lims_payment_data->delete();
        return redirect('purchases')->with('not_permitted', __('db.Payment deleted successfully'));
    }

    /**
     * Receive what each line physically received; ordered/pending lines (received 0) have no stock effect.
     * Warehouse prices are filled only where the projection row has none, as the legacy writer did for new rows.
     */
    private function postPurchaseStock(Purchase $purchase, array $warehousePrices = []): void
    {
        $posting = app(LegacyInventoryPosting::class);
        $posting->post($purchase, 'receive', $posting->purchaseLines(ProductPurchase::where('purchase_id', $purchase->id)->get()), (int) $purchase->warehouse_id);
        foreach ($warehousePrices as [$productId, $variantId, $batchId, $price]) {
            Product_Warehouse::where('product_id', $productId)->where('warehouse_id', $purchase->warehouse_id)
                ->when($variantId, fn ($query) => $query->where('variant_id', $variantId))
                ->when($batchId, fn ($query) => $query->where('product_batch_id', $batchId))
                ->whereNull('price')->update(['price' => $price]);
        }
    }

    /** Take back what the purchase received: its applied movements, or its persisted lines for pre-cutover history. */
    private function reversePurchaseStock(Purchase $purchase): void
    {
        $posting = app(LegacyInventoryPosting::class);
        $posting->reverse($purchase, function () use ($posting, $purchase) {
            $posting->post($purchase, 'issue', $posting->purchaseLines(ProductPurchase::where('purchase_id', $purchase->id)->get()), (int) $purchase->warehouse_id);
        });
    }

    private function purchaseHasSale($lims_product_purchase_data)
    {
        $has_sale = false;
        foreach ($lims_product_purchase_data as $product_purchase_data) {
            $product_sale = Product_Sale::where('product_id', $product_purchase_data->product_id)
                ->select('updated_at')
                ->latest('updated_at')
                ->first();

            if (!$product_sale) {
                continue;
            }

            if ($product_sale->updated_at->gt($product_purchase_data->updated_at)) {
                $has_sale = true;
            }
        }

        return $has_sale;
    }

    public function deleteBySelection(Request $request)
    {
        $purchase_id = $request['purchaseIdArray'];
        try {
            DB::beginTransaction();
            foreach ($purchase_id as $id) {
                $role = Role::find(Auth::user()->role_id);
                if($role->hasPermissionTo('purchases-delete')){
                    $lims_purchase_data = Purchase::whereKey($id)->lockForUpdate()->firstOrFail();
                    $lims_product_purchase_data = ProductPurchase::where('purchase_id', $id)->get();

                    if ($this->purchaseHasSale($lims_product_purchase_data)) {
                        return response()->json(['deleted' => [], 'message' =>  'Can not delete, purchase has sale!'], 403);
                    }

                    $this->fileDelete(public_path('documents/purchase/'), $lims_purchase_data->document);


                    $lims_payment_data = Payment::where('purchase_id', $id)->get();
                    $log_data['item_description'] = '';
                // Return what the purchase received before its lines are removed.
                $this->reversePurchaseStock($lims_purchase_data);
                foreach ($lims_product_purchase_data as $product_purchase_data) {
                    $lims_purchase_unit_data = Unit::find($product_purchase_data->purchase_unit_id);
                    $lims_product_data = Product::find($product_purchase_data->product_id);
                    $log_data['item_description'] .= $lims_product_data->name. '-'. $product_purchase_data->recieved.' '.$lims_purchase_unit_data->unit_code.'<br>';
    
                    $product_purchase_data->delete();
                }
                    $lims_pos_setting_data = PosSetting::latest()->first();
                    foreach ($lims_payment_data as $payment_data) {
                        if($payment_data->paying_method == "Cheque"){
                            $payment_with_cheque_data = PaymentWithCheque::where('payment_id', $payment_data->id)->first();
                            $payment_with_cheque_data->delete();
                        }
                        elseif($payment_data->paying_method == "Credit Card" && $lims_pos_setting_data->stripe_secret_key) {
                            $payment_with_credit_card_data = PaymentWithCreditCard::where('payment_id', $payment_data->id)->first();
                            \Stripe\Stripe::setApiKey($lims_pos_setting_data->stripe_secret_key);
                            \Stripe\Refund::create(array(
                            "charge" => $payment_with_credit_card_data->charge_id,
                            ));

                            $payment_with_credit_card_data->delete();
                        }
                        $payment_data->delete();
                    }

                    $lims_purchase_data->deleted_by = Auth::id();
                    $lims_purchase_data->save();

                    //creating log
                    $log_data['action'] = 'Purchase Deleted';
                    $log_data['user_id'] = Auth::id();
                    $log_data['reference_no'] = $lims_purchase_data->reference_no;
                    $log_data['date'] = $lims_purchase_data->created_at->toDateString();
                    // $log_data['admin_email'] = config('admin_email');
                    $log_data['admin_message'] = Auth::user()->name . ' has deleted a purchase. Reference No: ' .$lims_purchase_data->reference_no;
                    $log_data['user_email'] = Auth::user()->email;
                    $log_data['user_name'] = Auth::user()->name;
                    $log_data['user_message'] = 'You just deleted a purchase. Reference No: ' .$lims_purchase_data->reference_no;
                    // $log_data['mail_setting'] = $mail_setting = MailSetting::latest()->first();
                    $this->createActivityLog($log_data);

                    $lims_purchase_data->delete();
                    $this->fileDelete(public_path('documents/purchase/'), $lims_purchase_data->document);
                }
            }
            DB::commit();
            return response()->json(['deleted' => [], 'message' =>  'Purchase deleted successfully!']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['deleted' => [], 'message' =>  $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        return DB::transaction(fn () => $this->destroyPurchase($id));
    }

    private function destroyPurchase($id)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('purchases-delete')){
            $lims_purchase_data = Purchase::whereKey($id)->lockForUpdate()->firstOrFail();
            $lims_product_purchase_data = ProductPurchase::where('purchase_id', $id)->get();

            if ($this->purchaseHasSale($lims_product_purchase_data)) {
                return redirect('purchases')->with('not_permitted', __('db.Can not delete, purchase has sale!'));
            }

            $this->fileDelete(public_path('documents/purchase/'), $lims_purchase_data->document);

            $lims_payment_data = Payment::where('purchase_id', $id)->get();
            $log_data['item_description'] = '';
            // Return what the purchase received before its lines are removed.
            $this->reversePurchaseStock($lims_purchase_data);
            foreach ($lims_product_purchase_data as $product_purchase_data) {
                $lims_purchase_unit_data = Unit::find($product_purchase_data->purchase_unit_id);
                $lims_product_data = Product::find($product_purchase_data->product_id);
                $log_data['item_description'] .= $lims_product_data->name. '-'. $product_purchase_data->recieved.' '.$lims_purchase_unit_data->unit_code.'<br>';

                $product_purchase_data->delete();
            }
            $lims_pos_setting_data = PosSetting::latest()->first();
            foreach ($lims_payment_data as $payment_data) {
                if($payment_data->paying_method == "Cheque"){
                    $payment_with_cheque_data = PaymentWithCheque::where('payment_id', $payment_data->id)->first();
                    $payment_with_cheque_data->delete();
                }
                elseif($payment_data->paying_method == "Credit Card" && $lims_pos_setting_data->stripe_secret_key) {
                    $payment_with_credit_card_data = PaymentWithCreditCard::where('payment_id', $payment_data->id)->first();
                    \Stripe\Stripe::setApiKey($lims_pos_setting_data->stripe_secret_key);
                    \Stripe\Refund::create(array(
                      "charge" => $payment_with_credit_card_data->charge_id,
                    ));

                    $payment_with_credit_card_data->delete();
                }
                $payment_data->delete();
            }

            $lims_purchase_data->deleted_by = Auth::id();
            $lims_purchase_data->save();

            //creating log
            $log_data['action'] = 'Purchase Deleted';
            $log_data['user_id'] = Auth::id();
            $log_data['reference_no'] = $lims_purchase_data->reference_no;
            $log_data['date'] = $lims_purchase_data->created_at->toDateString();
            // $log_data['admin_email'] = config('admin_email');
            $log_data['admin_message'] = Auth::user()->name . ' has deleted a purchase. Reference No: ' .$lims_purchase_data->reference_no;
            $log_data['user_email'] = Auth::user()->email;
            $log_data['user_name'] = Auth::user()->name;
            $log_data['user_message'] = 'You just deleted a purchase. Reference No: ' .$lims_purchase_data->reference_no;
            // $log_data['mail_setting'] = $mail_setting = MailSetting::latest()->first();
            $this->createActivityLog($log_data);

            $lims_purchase_data->delete();
            $this->fileDelete(public_path('documents/purchase/'), $lims_purchase_data->document);

            return redirect('purchases')->with('not_permitted', __('db.Purchase deleted successfully'));
        }

    }

    public function updateFromClient(Request $request, $id)
    {
        $data = $request->except('document');
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
                $document->move(public_path('documents/purchase'), $documentName);
            }
            else {
                $documentName = $this->getTenantId() . '_' . $documentName . '.' . $ext;
                $document->move(public_path('documents/purchase'), $documentName);
            }
            $data['document'] = $documentName;
        }
        //return dd($data);
        DB::beginTransaction();
        try {
            $balance = $data['grand_total'] - $data['paid_amount'];
            if ($balance < 0 || $balance > 0) {
                $data['payment_status'] = 1;
            } else {
                $data['payment_status'] = 2;
            }
            $lims_purchase_data = Purchase::whereKey($id)->lockForUpdate()->firstOrFail();
            $lims_product_purchase_data = ProductPurchase::where('purchase_id', $id)->get();

            $data['created_at'] = date("Y-m-d", strtotime(str_replace("/", "-", $data['created_at'])));
            $product_id = $data['product_id'];
            $product_code = $data['product_code'];
            $qty = $data['qty'];
            $recieved = $data['recieved'];
            $batch_no = $data['batch_no'];
            $expired_date = $data['expired_date'];
            $purchase_unit = $data['purchase_unit'];
            $net_unit_cost = $data['net_unit_cost'];
            $discount = $data['discount'];
            $tax_rate = $data['tax_rate'];
            $tax = $data['tax'];
            $total = $data['subtotal'];
            $imei_number = $new_imei_number = $data['imei_number'];
            $product_purchase = [];

            // Return what the purchase received before its lines change; the new lines are received after the update.
            $this->reversePurchaseStock($lims_purchase_data);
            foreach ($lims_product_purchase_data as $product_purchase_data) {
                $product_purchase_data->delete();
            }

            $warehousePrices = [];
            foreach ($product_id as $key => $pro_id) {
                $price = null;
                $lims_purchase_unit_data = Unit::where('unit_name', $purchase_unit[$key])->first();
                $lims_product_data = Product::find($pro_id);
                //dealing with product batch: only its identity here, the stock movement posts the quantity
                if($batch_no[$key]) {
                    $product_batch_data = ProductBatch::where([
                                            ['product_id', $lims_product_data->id],
                                            ['batch_no', $batch_no[$key]]
                                        ])->first();
                    if($product_batch_data) {
                        $product_batch_data->expired_date = $expired_date[$key];
                        $product_batch_data->save();
                    }
                    else {
                        $product_batch_data = ProductBatch::create([
                                                'product_id' => $lims_product_data->id,
                                                'batch_no' => $batch_no[$key],
                                                'expired_date' => $expired_date[$key],
                                                'qty' => 0
                                            ]);
                    }
                    $product_purchase['product_batch_id'] = $product_batch_data->id;
                }
                else
                    $product_purchase['product_batch_id'] = null;

                $product_purchase['variant_id'] = null;
                if($lims_product_data->is_variant) {
                    $lims_product_variant_data = ProductVariant::select('id', 'variant_id')->FindExactProductWithCode($pro_id, $product_code[$key])->first();
                    if($lims_product_variant_data) {
                        $product_purchase['variant_id'] = $lims_product_variant_data->variant_id;
                    }
                }
                elseif($product_purchase['product_batch_id']) {
                    //checking for price
                    $lims_product_warehouse_data = Product_Warehouse::where([
                                                    ['product_id', $pro_id],
                                                    ['warehouse_id', $data['warehouse_id'] ],
                                                ])
                                                ->whereNotNull('price')
                                                ->select('price')
                                                ->first();
                    if($lims_product_warehouse_data)
                        $price = $lims_product_warehouse_data->price;
                }
                if($price) {
                    $warehousePrices[] = [$pro_id, $product_purchase['variant_id'], $product_purchase['product_batch_id'], $price];
                }

                $product_purchase['purchase_id'] = $id ;
                $product_purchase['product_id'] = $pro_id;
                $product_purchase['qty'] = $qty[$key];
                $product_purchase['recieved'] = $recieved[$key];
                $product_purchase['purchase_unit_id'] = $lims_purchase_unit_data->id;
                $product_purchase['net_unit_cost'] = $net_unit_cost[$key];
                $product_purchase['discount'] = $discount[$key];
                $product_purchase['tax_rate'] = $tax_rate[$key];
                $product_purchase['tax'] = $tax[$key];
                $product_purchase['total'] = $total[$key];
                $product_purchase['imei_number'] = $imei_number[$key];
                ProductPurchase::create($product_purchase);
            }
            $lims_purchase_data->update($data);
            $this->postPurchaseStock($lims_purchase_data->refresh(), $warehousePrices);
            DB::commit();
        }
        catch(\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()]);
        }
        return redirect('purchases')->with('message', __('db.Purchase updated successfully'));
    }

    public function showDeletedPurchases()
    {
        $lims_deleted_data = Purchase::onlyTrashed()
            ->with(['user', 'supplier', 'warehouse', 'deleter'])
            ->get();

        return view('backend.purchase.deleted-data', compact('lims_deleted_data'));
    }

    public function forceDeleteSelected(Request $request)
    {
        $ids = $request->ids ?? [];

        if (!empty($ids)) {
            Purchase::withTrashed()->whereIn('id', $ids)->forceDelete();
            return back()->with('not_permitted', 'Selected purchases deleted permanently!');
        }

        return back()->with('not_permitted', 'No purchases selected!');
    }

    public function supplierPurchase($supplier_id)
    {
        $purchases = Purchase::with('supplier')
            ->where('supplier_id', $supplier_id)
            ->latest()
            ->get()
            ->map(function ($purchase) {
                $purchaseStatus = match($purchase->status) {
                    1 => 'Received',
                    2 => 'Partial',
                    3 => 'Pending',
                    default => 'Ordered',
                };

                $paymentStatus = $purchase->paid_amount >= $purchase->grand_total ? 'Paid' :
                                ($purchase->paid_amount > 0 ? 'Partial' : 'Due');

                $paymentDue = number_format($purchase->grand_total - $purchase->paid_amount, 2);

                $warehouseName = $purchase->warehouse_id ? optional(Warehouse::find($purchase->warehouse_id))->name : '-';
                $supplier = $purchase->supplier;

                return [
                    'id' => $purchase->id,
                    'date' => $purchase->created_at->format('Y-m-d'),
                    'reference' => $purchase->reference_no,
                    'warehouse' => $warehouseName,
                    'purchase_status' => $purchaseStatus,
                    'payment_status' => $paymentStatus,
                    'grand_total' => number_format($purchase->grand_total, 2),
                    'paid_amount' => number_format($purchase->paid_amount, 2),
                    'payment_due' => $paymentDue,
                    'note' => $purchase->note,
                    'currency' => $purchase->currency ?? null,
                    'document' => $purchase->document ?? null,
                    'supplier_name' => $supplier->name ?? '-',
                    'supplier_company' => $supplier->company_name ?? '-',
                    'supplier_address' => $supplier->address ?? '-',
                ];
            });

        return response()->json(['data' => $purchases]);
    }

    public function saleData(Request $request)
    {
        $columns = array(
            2 => 'created_at',
            3 => 'reference_no',
            4 => 'customer_id',
            5 => 'warehouse_id',
            6 => 'sale_status',
            7 => 'payment_status',
            10 => 'grand_total',
            12 => 'paid_amount',
        );

        $warehouse_id = $request->input('warehouse_id');
        $sale_status = $request->input('sale_status');
        $payment_status = $request->input('payment_status');
        $sale_type = $request->input('sale_type');
        $payment_method = $request->input('payment_method');

        // $q = Sale::whereDate('sales.created_at', '>=' ,$request->input('starting_date'))->whereDate('sales.created_at', '<=' ,$request->input('ending_date'));
        $q = Sale::join('payments', 'sales.id', '=', 'payments.sale_id')
                ->whereNull('sales.deleted_at')
                ->whereDate('sales.created_at', '>=', $request->input('starting_date'))
                ->whereDate('sales.created_at', '<=', $request->input('ending_date'))
                ->select('sales.id', 'sales.*','payments.paying_method');

        if(Auth::user()->role_id > 2 && config('staff_access') == 'own')
            $q = $q->where('sales.user_id', Auth::id());
        elseif(Auth::user()->role_id > 2 && config('staff_access') == 'warehouse')
            $q = $q->where('sales.warehouse_id', Auth::user()->warehouse_id);
        if($sale_status)
            $q = $q->where('sales.sale_status', $sale_status);
        if($payment_status)
            $q = $q->where('sales.payment_status', $payment_status);
        if($sale_type)
            $q = $q->where('sales.sale_type', $sale_type);
        if($payment_method)
            $q = $q->where('payments.paying_method', $payment_method);

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'sales.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        //fetching custom fields data
        $custom_fields = CustomField::where([
                        ['belongs_to', 'sale'],
                        ['is_table', true]
                    ])->pluck('name');
        $field_names = [];
        foreach($custom_fields as $fieldName) {
            $field_names[] = str_replace(" ", "_", strtolower($fieldName));
        }
        if(empty($request->input('search.value'))) {
            $q = Sale::with('biller', 'customer', 'warehouse', 'user')
                ->whereNull('sales.deleted_at')
                ->whereDate('sales.created_at', '>=' ,$request->input('starting_date'))
                ->whereDate('sales.created_at', '<=' ,$request->input('ending_date'));

            if(Auth::user()->role_id > 2 && config('staff_access') == 'own')
                $q = $q->where('sales.user_id', Auth::id());
            elseif(Auth::user()->role_id > 2 && config('staff_access') == 'warehouse')
                $q = $q->where('sales.warehouse_id', Auth::user()->warehouse_id);
            if($warehouse_id)
                $q = $q->where('sales.warehouse_id', $warehouse_id);
            if($sale_status)
                $q = $q->where('sales.sale_status', $sale_status);
            if($payment_status)
                $q = $q->where('sales.payment_status', $payment_status);
            if($sale_type)
                $q = $q->where('sales.sale_type', $sale_type);
            if($payment_method)
                $q = $q->join('payments','sales.id','=','payments.sale_id')->select('sales.id','sales.*','payments.paying_method')->where('payments.paying_method', $payment_method);

            $totalData = $q->count();
            $totalFiltered = $totalData;

            if($request->input('length') != -1)
                $limit = $request->input('length');
            else
                $limit = $totalData;
            $start = $request->input('start');
            $order = 'sales.'.$columns[$request->input('order.0.column')];
            $dir = $request->input('order.0.dir');

            $q->offset($start)->limit($limit)->orderBy($order, $dir);

            $sales = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = Sale::join('product_sales', 'sales.id', '=', 'product_sales.sale_id')
                ->leftJoin('billers', 'sales.biller_id', '=', 'billers.id')
                ->leftJoin('customers', 'sales.customer_id', '=', 'customers.id')
                ->leftJoin('products', 'product_sales.product_id', '=', 'products.id')
                ->whereNull('sales.deleted_at')
                ->whereDate('sales.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))))
                ->offset($start)
                ->limit($limit)
                ->orderBy($order,$dir);
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $q = $q->select('sales.*')
                        ->with('biller', 'customer', 'warehouse', 'user')
                        ->where('sales.user_id', Auth::id())
                        ->orwhere([
                            ['sales.reference_no', 'LIKE', "%{$search}%"],
                            ['sales.user_id', Auth::id()]
                        ])
                        ->orwhere([
                            ['customers.name', 'LIKE', "%{$search}%"],
                            ['sales.user_id', Auth::id()]
                        ])
                        ->orwhere([
                            ['customers.phone_number', 'LIKE', "%{$search}%"],
                            ['sales.user_id', Auth::id()]
                        ])
                        ->orwhere([
                            ['billers.name', 'LIKE', "%{$search}%"],
                            ['sales.user_id', Auth::id()]
                        ])
                        ->orwhere([
                            ['product_sales.imei_number', 'LIKE', "%{$search}%"],
                            ['sales.user_id', Auth::id()]
                        ]);
                foreach ($field_names as $key => $field_name) {
                    $q = $q->orwhere([
                            ['sales.user_id', Auth::id()],
                            ['sales.' . $field_name, 'LIKE', "%{$search}%"]
                        ]);
                }
            }
            elseif(Auth::user()->role_id > 2 && config('staff_access') == 'warehouse') {
                $q = $q->select('sales.*')
                        ->with('biller', 'customer', 'warehouse', 'user')
                        ->where('sales.user_id', Auth::id())
                        ->orwhere([
                            ['sales.reference_no', 'LIKE', "%{$search}%"],
                            ['sales.warehouse_id', Auth::user()->warehouse_id]
                        ])
                        ->orwhere([
                            ['customers.name', 'LIKE', "%{$search}%"],
                            ['sales.warehouse_id', Auth::user()->warehouse_id]
                        ])
                        ->orwhere([
                            ['customers.phone_number', 'LIKE', "%{$search}%"],
                            ['sales.warehouse_id', Auth::user()->warehouse_id]
                        ])
                        ->orwhere([
                            ['billers.name', 'LIKE', "%{$search}%"],
                            ['sales.warehouse_id', Auth::user()->warehouse_id]
                        ])
                        ->orwhere([
                            ['product_sales.imei_number', 'LIKE', "%{$search}%"],
                            ['sales.warehouse_id', Auth::user()->warehouse_id]
                        ]);
                foreach ($field_names as $key => $field_name) {
                    $q = $q->orwhere([
                            ['sales.user_id', Auth::id()],
                            ['sales.warehouse_id', Auth::user()->warehouse_id]
                        ]);
                }
            }
            else {
                $q = $q->select('sales.*')
                        ->with('biller', 'customer', 'warehouse', 'user')
                        ->orwhere('sales.reference_no', 'LIKE', "%{$search}%")
                        ->orwhere('customers.name', 'LIKE', "%{$search}%")
                        ->orwhere('customers.phone_number', 'LIKE', "%{$search}%")
                        ->orwhere('billers.name', 'LIKE', "%{$search}%")
                        ->orwhere('product_sales.imei_number', 'LIKE', "%{$search}%")
                        ->orWhere('products.name', 'LIKE', "%{$search}%");
                        // ->orWhere('products.code', 'LIKE', "%{$search}%");
                foreach ($field_names as $key => $field_name) {
                    $q = $q->orwhere('sales.' . $field_name, 'LIKE', "%{$search}%");
                }
            }
            $sales = $q->groupBy('sales.id')->get();

            $totalFiltered = $q->groupBy('sales.id')->count();
        }
        $data = array();
        if(!empty($sales))
        {
            // return $sales;
            foreach ($sales as $key=>$sale)
            {
                $lims_installment_plan_data = DB::table('installment_plans')
                                            ->where([
                                                ['reference_type', 'sale'],
                                                ['reference_id', $sale->id]
                                            ])->first();
                if ($lims_installment_plan_data) {
                    // dd($lims_installment_plan_data);
                }
                // return dd($sale);
                if($sale->currency_id){
                    $currency_code = Currency::select('code')->find($sale->currency_id)->code;
                    $currency = $currency_code . '/'.$sale->exchange_rate;
                }else{
                    $currency_code = 'N/A';
                }
                $nestedData['id'] = $sale->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format').' h:i:s a', strtotime($sale->created_at));
                //$nestedData['date'] = $sale->created_at;
                $nestedData['reference_no'] = $sale->reference_no;
                //$nestedData['biller'] = $sale->biller->name;
                $nestedData['customer'] = $sale->customer->name.'<br>'.$sale->customer->phone_number.'<input type="hidden" class="deposit" value="'.($sale->customer->deposit - $sale->customer->expense).'" />'.'<input type="hidden" class="points" value="'.$sale->customer->points.'" />';
                // new column warehouse added in sale list. [09.02.2025]
                $warehouse = Warehouse::select('name')->where('id', $sale->warehouse_id)->first();
                $nestedData['warehouse_name'] = $warehouse->name;
                $nestedData['currency'] = $currency ?? 'N/A';
                // products details
                $nestedData['products'] = [];
                $nestedData['qty'] = [];

                $productNames = [];
                $productQtys = [];

                $total_products = $sale->products->count();

                foreach ($sale->products as $key => $product) {
                    $product_sale = Product_Sale::where([
                        'product_id' => $product->id,
                        'sale_id' => $sale->id
                    ])->first();
                    if ($key + 1 < $total_products) {
                        $productNames[] = '<div style="border-bottom: 1px solid #ccc; padding-bottom: 4px; margin-bottom: 4px;">'
                                        . e($product->name) . '</div>';
                    } else {
                        $productNames[] = '<div style="padding-bottom: 4px; margin-bottom: 4px;">'
                                        . e($product->name) . '</div>';
                    }
                    $productQtys[] = '<div style="padding-bottom: 4px; margin-bottom: 4px;">'
                                    . '<span class="badge badge-primary">' . e($product_sale->qty) . '</span></div>';
                }
                $nestedData['products'] = implode('', $productNames);
                $nestedData['qty'] = implode('', $productQtys);

                if(!$sale->exchange_rate || $sale->exchange_rate == 0)
                    $sale->exchange_rate = 1;

                $payments = Payment::where('sale_id', $sale->id)->select('amount','paying_method')->get();
                $paymentMethods = $payments->map(function ($payment) use ($sale) {
                    return ucfirst($payment->paying_method ?? '') .
                        '(' . number_format($payment->amount / $sale->exchange_rate,  config('decimal')) . ')';
                })->implode(', ');

                $nestedData['payment_method'] = $paymentMethods;

                if($sale->sale_status == 1){
                    $nestedData['sale_status'] = '<div class="badge badge-success">'.__('db.Completed').'</div>';
                    $sale_status = __('db.Completed');
                }
                elseif($sale->sale_status == 2){
                    $nestedData['sale_status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                    $sale_status = __('db.Pending');
                }
                elseif($sale->sale_status == 3){
                    $nestedData['sale_status'] = '<div class="badge badge-warning">'.__('db.Draft').'</div>';
                    $sale_status = __('db.Draft');
                }
                elseif($sale->sale_status == 4){
                    $nestedData['sale_status'] = '<div class="badge badge-danger">'.__('db.Returned').'</div>';
                    $sale_status = __('db.Returned');
                }
                elseif($sale->sale_status == 5){
                    $nestedData['sale_status'] = '<div class="badge badge-info">'.__('db.Processing').'</div>';
                    $sale_status = __('db.Processing');
                }
                elseif($sale->sale_status == 6){
                    $nestedData['sale_status'] = '<div class="badge badge-danger">'.__('db.Cooked').'</div>';
                    $sale_status = __('db.Cooked');
                }
                elseif($sale->sale_status == 7){
                    $nestedData['sale_status'] = '<div class="badge badge-primary">'.__('db.Served').'</div>';
                    $sale_status = __('db.Served');
                }

                if($sale->payment_status == 1)
                    $nestedData['payment_status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                elseif($sale->payment_status == 2)
                    $nestedData['payment_status'] = '<div class="badge badge-danger">'.__('db.Due').'</div>';
                elseif($sale->payment_status == 3)
                    $nestedData['payment_status'] = '<div class="badge badge-warning">'.__('db.Partial').'</div>';
                else
                    $nestedData['payment_status'] = '<div class="badge badge-success">'.__('db.Paid').'</div>';
                $delivery_data = DB::table('deliveries')->select('status')->where('sale_id', $sale->id)->first();
                if($delivery_data) {
                    if($delivery_data->status == 1)
                        $nestedData['delivery_status'] = '<div class="badge badge-primary">'.__('db.Packing').'</div>';
                    elseif($delivery_data->status == 2)
                        $nestedData['delivery_status'] = '<div class="badge badge-info">'.__('db.Delivering').'</div>';
                    elseif($delivery_data->status == 3)
                        $nestedData['delivery_status'] = '<div class="badge badge-success">'.__('db.Delivered').'</div>';
                }
                else
                    $nestedData['delivery_status'] = 'N/A';

                $nestedData['grand_total'] = number_format($sale->grand_total / $sale->exchange_rate, config('decimal'));
                $returned_amount = DB::table('returns')->where('sale_id', $sale->id)->sum('grand_total');
                $nestedData['returned_amount'] = number_format($returned_amount / $sale->exchange_rate, config('decimal'));
                $nestedData['paid_amount'] = number_format($sale->paid_amount / $sale->exchange_rate, config('decimal'));
                $nestedData['due'] = number_format(($sale->grand_total - $returned_amount - $sale->paid_amount) / $sale->exchange_rate, config('decimal'));
                //fetching custom fields data
                foreach($field_names as $field_name) {
                    $nestedData[$field_name] = $sale->$field_name;
                }
                $nestedData['options'] = '<div class="btn-group">
                            <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'.__("db.action").'
                              <span class="caret"></span>
                              <span class="sr-only">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" user="menu">
                                <li><a href="'.route('sale.invoice', $sale->id).'" class="btn btn-link gen-invoice"><i class="fa fa-copy"></i> '.__('db.Generate Invoice').'</a></li>
                                <li>
                                    <button type="button" class="btn btn-link view"><i class="fa fa-eye"></i> '.__('db.View').'</button>
                                </li>';
                if(in_array("sales-edit", $request['all_permission'])){
                    if($sale->sale_status != 3)
                        $nestedData['options'] .= '<li>
                            <a href="'.route('sales.edit', $sale->id).'" class="btn btn-link"><i class="dripicons-document-edit"></i> '.__('db.edit').'</a>
                            </li>';
                    else
                        $nestedData['options'] .= '<li>
                            <a href="'.url('pos/'.$sale->id).'" class="btn btn-link"><i class="dripicons-document-edit"></i> '.__('db.edit').'</a>
                        </li>';
                }
                if ($lims_installment_plan_data) {
                    $nestedData['options'] .= '<li>
                        <a href="'.route('installmentplan.show', $lims_installment_plan_data->id).'" class="btn btn-link"><i class="fa fa-info-circle"></i> '.__('db.Installment Plan').'</a>
                    </li>';
                }
                if(config('is_packing_slip') && in_array("packing_slip_challan", $request['all_permission']) && ($sale->sale_status == 2 || $sale->sale_status == 5) ) {
                    $nestedData['options'] .=
                    '<li>
                        <button type="button" class="create-packing-slip-btn btn btn-link" data-id = "'.$sale->id.'" data-toggle="modal" data-target="#packing-slip-modal"><i class="dripicons-box"></i> '.__('db.Create Packing Slip').'</button>
                    </li>';
                }
                if(in_array("sale-payment-index", $request['all_permission']))
                    $nestedData['options'] .=
                        '<li>
                            <button type="button" class="get-payment btn btn-link" data-id = "'.$sale->id.'"><i class="fa fa-money"></i> '.__('db.View Payment').'</button>
                        </li>';
                if(in_array("sale-payment-add", $request['all_permission']) && ($sale->payment_status != 4) && ($sale->sale_status != 3)) {
                    $currency_code_name = $sale->currency->code ?? 'USD';
                    $nestedData['options'] .=
                        ' <li>
                            <button
                                type="button"
                                class="add-payment btn btn-link"
                                data-id="'.$sale->id.'"
                                data-currency_id="'.$sale->currency_id.'"
                                data-currency_name="'.$currency_code_name.'"
                                data-exchange_rate="'.$sale->exchange_rate.'"
                                data-toggle="modal"
                                data-target="#add-payment">
                                <i class="fa fa-plus"></i> '.__('db.Add Payment').'
                            </button>
                        </li>';
                }
                if($sale->sale_status !== 4)
                    $nestedData['options'] .=
                    '<li>
                        <a href="return-sale/create?reference_no='.$nestedData['reference_no'].'" class="add-payment btn btn-link"><i class="dripicons-return"></i> '.__('db.Add Return').'</a>
                    </li>';

                $nestedData['options'] .=
                '<li>
                    <button type="button" class="send-sms btn btn-link" data-id = "'.$sale->id.'" data-customer_id="'.$sale->customer_id.'" data-reference_no="'.$nestedData['reference_no'].'" data-sale_status="'.$sale->sale_status.'" data-payment_status="'.$sale->payment_status.'"  data-toggle="modal" data-target="#send-sms"><i class="fa fa-envelope"></i> '.__('db.Send SMS').'</button>
                </li>';

                $nestedData['options'] .=
                '<li>
                    <form action="'.route('sale.wappnotification').'" method="POST" style="display:inline;">
                      '.csrf_field().'
                        <input type="hidden" name="customer_id" value="'.$sale->customer_id.'">
                        <input type="hidden" name="sale_id" value="'.$sale->id.'">
                        <button type="submit" class="btn btn-link">
                            <i class="fa fa-whatsapp"></i> '.__('db.Whatsapp Notification').'
                        </button>
                    </form>
                </li>';

                $nestedData['options'] .=
                    '<li>
                        <button type="button" class="add-delivery btn btn-link" data-id = "'.$sale->id.'"><i class="fa fa-truck"></i> '.__('db.Add Delivery').'</button>
                    </li>';
                if(in_array("sales-delete", $request['all_permission']))
                    $nestedData['options'] .= \Form::open(["route" => ["sales.destroy", $sale->id], "method" => "DELETE"] ).'
                            <li>
                              <button type="submit" class="btn btn-link" onclick="return confirmDelete()"><i class="dripicons-trash"></i> '.__("db.delete").'</button>
                            </li>'.\Form::close().'
                        </ul>
                    </div>';
                // data for sale details by one click
                $coupon = Coupon::find($sale->coupon_id);
                if($coupon)
                    $coupon_code = $coupon->code;
                else
                    $coupon_code = null;



                // table data
                if(!empty($sale->table_id)){
                    $table = Table::findOrFail($sale->table_id);
                    if($table)
                        $table_name = $table->name;
                    else
                        $table_name = '';
                }
                else
                    $table_name = '';

                $nestedData['sale'] = array( '[ "'.date(config('date_format'), strtotime($sale->created_at->toDateString())).'"', ' "'.$sale->reference_no.'"', ' "'.$sale_status.'"', ' "'.@$sale->biller->name.'"', ' "'.@$sale->biller->company_name.'"', ' "'.@$sale->biller->email.'"', ' "'.@$sale->biller->phone_number.'"', ' "'.@$sale->biller->address.'"', ' "'.@$sale->biller->city.'"', ' "'.@$sale->customer->name.'"', ' "'.@$sale->customer->phone_number.'"', ' "'.@$sale->customer->address.'"', ' "'.@$sale->customer->city.'"', ' "'.@$sale->id.'"', ' "'.@$sale->total_tax.'"', ' "'.$sale->total_discount.'"', ' "'.$sale->total_price.'"', ' "'.$sale->order_tax.'"', ' "'.$sale->order_tax_rate.'"', ' "'.$sale->order_discount.'"', ' "'.$sale->shipping_cost.'"', ' "'.$sale->grand_total.'"', ' "'.$sale->paid_amount.'"', ' "'.preg_replace('/[\n\r]/', "<br>", $sale->sale_note).'"', ' "'.preg_replace('/[\n\r]/', "<br>", $sale->staff_note).'"', ' "'.$sale->user->name.'"', ' "'.$sale->user->email.'"', ' "'.$sale->warehouse->name.'"', ' "'.$coupon_code.'"', ' "'.$sale->coupon_discount.'"', ' "'.$sale->document.'"', ' "'.$currency_code.'"', ' "'.$sale->exchange_rate.'"', ' "'.$table_name.'"]'
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
}
