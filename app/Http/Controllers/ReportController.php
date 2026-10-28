<?php

namespace App\Http\Controllers;

use DB;
use Auth;
use Carbon\Carbon;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Models\Biller;
use App\Models\Income;
use App\Models\Challan;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\Product;
use App\Models\Returns;
use App\Models\Variant;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Transfer;
use App\Models\Quotation;
use App\Models\Warehouse;
use App\Models\CustomField;
use App\Models\Product_Sale;
use Illuminate\Http\Request;
use App\Models\CustomerGroup;
use App\Models\ProductReturn;
use App\Models\GeneralSetting;
use App\Models\ProductVariant;
use App\Models\ReturnPurchase;
use App\Models\ProductPurchase;
use App\Models\ProductTransfer;
use App\Models\ProductQuotation;
use App\Models\Product_Warehouse;
use Spatie\Permission\Models\Role;
use App\Models\PurchaseProductReturn;
use Spatie\Permission\Models\Permission;

class ReportController extends Controller
{
    public function productQuantityAlert()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('product-qty-alert')){
            $lims_product_data = Product::select('name','code', 'image', 'qty', 'alert_quantity')->where('is_active', true)->whereColumn('alert_quantity', '>', 'qty')->get();
            return view('backend.report.qty_alert_report', compact('lims_product_data'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function dailySaleObjective(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('dso-report')) {
            if($request->input('starting_date')) {
                $starting_date = $request->input('starting_date');
                $ending_date = $request->input('ending_date');
            }
            else {
                $starting_date = date("Y-m-d", strtotime(date('Y-m-d', strtotime('-1 month', strtotime(date('Y-m-d') )))));
                $ending_date = date("Y-m-d");
            }
            return view('backend.report.daily_sale_objective', compact('starting_date', 'ending_date'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function dailySaleObjectiveData(Request $request)
    {
        $starting_date = date("Y-m-d", strtotime("+1 day", strtotime($request->input('starting_date'))));
        $ending_date = date("Y-m-d", strtotime("+1 day", strtotime($request->input('ending_date'))));

        $columns = array(
            1 => 'created_at',
        );
        $totalData = DB::table('dso_alerts')
                    ->whereDate('created_at', '>=' , $starting_date)
                    ->whereDate('created_at', '<=' , $ending_date)
                    ->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        if(empty($request->input('search.value'))) {
            $lims_dso_alert_data = DB::table('dso_alerts')
                                  ->whereDate('created_at', '>=' , $starting_date)
                                  ->whereDate('created_at', '<=' , $ending_date)
                                  ->offset($start)
                                  ->limit($limit)
                                  ->orderBy($order, $dir)
                                  ->get();
        }
        else
        {
            $search = $request->input('search.value');
            $lims_dso_alert_data = DB::table('dso_alerts')
                                  ->whereDate('dso_alerts.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))))
                                  ->offset($start)
                                  ->limit($limit)
                                  ->orderBy($order, $dir)
                                  ->get();
        }
        $data = array();
        if(!empty($lims_dso_alert_data))
        {
            foreach ($lims_dso_alert_data as $key => $dso_alert_data)
            {
                $nestedData['id'] = $dso_alert_data->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime("-1 day", strtotime($dso_alert_data->created_at)));
                foreach (json_decode($dso_alert_data->product_info) as $index => $product_info) {
                    if($index)
                        $nestedData['product_info'] .= ', ';
                    $nestedData['product_info'] = $product_info->name.' ['.$product_info->code.']';
                }
                $nestedData['number_of_products'] = $dso_alert_data->number_of_products;
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

    public function productExpiry()
    {
        // $general_settings_data = GeneralSetting::select('expiry_type','expiry_value')->first();

        // $date = date('Y-m-d', strtotime('+'.$general_settings_data["expiry_value"].' '.$general_settings_data["expiry_type"]));
        $lims_product_data = DB::table('products')
                            ->join('product_batches', 'products.id', '=', 'product_batches.product_id')
                            // ->whereDate('product_batches.expired_date', '<=', $date)
                            ->where([
                                ['products.is_active', true],
                                ['product_batches.qty', '>', 0]
                            ])
                            ->select('products.name', 'products.code', 'products.image', 'product_batches.batch_no', 'product_batches.batch_no', 'product_batches.expired_date', 'product_batches.qty')
                            ->get();
        return view('backend.report.product_expiry_report', compact('lims_product_data'));
    }

    public function warehouseStock(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('warehouse-stock-report')) {
            if(isset($request->warehouse_id))
                $warehouse_id = $request->warehouse_id;
            else
                $warehouse_id = 0;
            if(!$warehouse_id) {
                $total_item = DB::table('product_warehouse')
                            ->join('products', 'product_warehouse.product_id', '=', 'products.id')
                            ->where([
                                ['products.is_active', true],
                                ['product_warehouse.qty', '>' , 0]
                            ])->count();

                $total_qty = \DB::table('product_warehouse')
                    ->join('products', 'product_warehouse.product_id', '=', 'products.id')
                    ->where('products.is_active', true)
                    ->sum('product_warehouse.qty');
                    
                $total_price = DB::table('products')->where('is_active', true)->sum(DB::raw('price * qty'));
                $total_cost = DB::table('products')->where('is_active', true)->sum(DB::raw('cost * qty'));
            }
            else {
                $total_item = DB::table('product_warehouse')
                            ->join('products', 'product_warehouse.product_id', '=', 'products.id')
                            ->where([
                                ['products.is_active', true],
                                ['product_warehouse.qty', '>' , 0],
                                ['product_warehouse.warehouse_id', $warehouse_id]
                            ])->count();
                $total_qty = DB::table('product_warehouse')
                                ->join('products', 'product_warehouse.product_id', '=', 'products.id')
                                ->where([
                                    ['products.is_active', true],
                                    ['product_warehouse.warehouse_id', $warehouse_id]
                                ])->sum('product_warehouse.qty');
                $total_price = DB::table('product_warehouse')
                                ->join('products', 'product_warehouse.product_id', '=', 'products.id')
                                ->where([
                                    ['products.is_active', true],
                                    ['product_warehouse.warehouse_id', $warehouse_id]
                                ])->sum(DB::raw('products.price * product_warehouse.qty'));
                $total_cost = DB::table('product_warehouse')
                                ->join('products', 'product_warehouse.product_id', '=', 'products.id')
                                ->where([
                                    ['products.is_active', true],
                                    ['product_warehouse.warehouse_id', $warehouse_id]
                                ])->sum(DB::raw('products.cost * product_warehouse.qty'));
            }

            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            return view('backend.report.warehouse_stock', compact('total_item', 'total_qty', 'total_price', 'total_cost', 'lims_warehouse_list', 'warehouse_id'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function dailySale($year, $month)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('daily-sale')){
            $start = 1;
            $number_of_day = date('t', mktime(0, 0, 0, $month, 1, $year));
            while($start <= $number_of_day)
            {
                if($start < 10)
                    $date = $year.'-'.$month.'-0'.$start;
                else
                    $date = $year.'-'.$month.'-'.$start;
                $query1 = array(
                    'SUM(total_discount / exchange_rate) as total_discount',
                    'SUM(order_discount / exchange_rate) as order_discount',
                    'SUM(total_tax / exchange_rate) as total_tax',
                    'SUM(order_tax / exchange_rate) as order_tax',
                    'SUM(shipping_cost / exchange_rate) as shipping_cost',
                    'SUM(grand_total / exchange_rate) as grand_total'
                );
                $sale_data = Sale::whereDate('created_at', $date)->whereNull('deleted_at')->selectRaw(implode(',', $query1))->get();

                $total_discount[$start] = number_format($sale_data[0]->total_discount, config('decimal'));
                $order_discount[$start] = number_format($sale_data[0]->order_discount, config('decimal'));
                $total_tax[$start] = number_format($sale_data[0]->total_tax, config('decimal'));
                $order_tax[$start] = number_format($sale_data[0]->order_tax, config('decimal'));
                $shipping_cost[$start] = number_format($sale_data[0]->shipping_cost, config('decimal'));
                $grand_total[$start] = number_format($sale_data[0]->grand_total, config('decimal'));
                $start++;
            }
            $start_day = date('w', strtotime($year.'-'.$month.'-01')) + 1;
            $prev_year = date('Y', strtotime('-1 month', strtotime($year.'-'.$month.'-01')));
            $prev_month = date('m', strtotime('-1 month', strtotime($year.'-'.$month.'-01')));
            $next_year = date('Y', strtotime('+1 month', strtotime($year.'-'.$month.'-01')));
            $next_month = date('m', strtotime('+1 month', strtotime($year.'-'.$month.'-01')));
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            $warehouse_id = 0;
            return view('backend.report.daily_sale', compact('total_discount','order_discount', 'total_tax', 'order_tax', 'shipping_cost', 'grand_total', 'start_day', 'year', 'month', 'number_of_day', 'prev_year', 'prev_month', 'next_year', 'next_month', 'lims_warehouse_list', 'warehouse_id'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function dailySaleByWarehouse(Request $request,$year,$month)
    {
        $data = $request->all();
        if($data['warehouse_id'] == 0)
            return redirect()->back();
        $start = 1;
        $number_of_day = date('t', mktime(0, 0, 0, $month, 1, $year));
        while($start <= $number_of_day)
        {
            if($start < 10)
                $date = $year.'-'.$month.'-0'.$start;
            else
                $date = $year.'-'.$month.'-'.$start;
            $query1 = array(
                'SUM(total_discount / exchange_rate) as total_discount',
                'SUM(order_discount / exchange_rate) as order_discount',
                'SUM(total_tax / exchange_rate) as total_tax',
                'SUM(order_tax / exchange_rate) as order_tax',
                'SUM(shipping_cost / exchange_rate) as shipping_cost',
                'SUM(grand_total / exchange_rate) as grand_total'
            );
            $sale_data = Sale::where('warehouse_id', $data['warehouse_id'])->whereDate('created_at', $date)->whereNull('deleted_at')->selectRaw(implode(',', $query1))->get();
            $total_discount[$start] = number_format($sale_data[0]->total_discount, config('decimal'));
            $order_discount[$start] = number_format($sale_data[0]->order_discount, config('decimal'));
            $total_tax[$start] = number_format($sale_data[0]->total_tax, config('decimal'));
            $order_tax[$start] = number_format($sale_data[0]->order_tax, config('decimal'));
            $shipping_cost[$start] = number_format($sale_data[0]->shipping_cost, config('decimal'));
            $grand_total[$start] = number_format($sale_data[0]->grand_total, config('decimal'));
            $start++;
        }
        $start_day = date('w', strtotime($year.'-'.$month.'-01')) + 1;
        $prev_year = date('Y', strtotime('-1 month', strtotime($year.'-'.$month.'-01')));
        $prev_month = date('m', strtotime('-1 month', strtotime($year.'-'.$month.'-01')));
        $next_year = date('Y', strtotime('+1 month', strtotime($year.'-'.$month.'-01')));
        $next_month = date('m', strtotime('+1 month', strtotime($year.'-'.$month.'-01')));
        $lims_warehouse_list = Warehouse::where('is_active', true)->get();
        $warehouse_id = $data['warehouse_id'];
        return view('backend.report.daily_sale', compact('total_discount','order_discount', 'total_tax', 'order_tax', 'shipping_cost', 'grand_total', 'start_day', 'year', 'month', 'number_of_day', 'prev_year', 'prev_month', 'next_year', 'next_month', 'lims_warehouse_list', 'warehouse_id'));

    }

    public function dailyPurchase($year, $month)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('daily-purchase')){
            $start = 1;
            $number_of_day = date('t', mktime(0, 0, 0, $month, 1, $year));
            while($start <= $number_of_day)
            {
                if($start < 10)
                    $date = $year.'-'.$month.'-0'.$start;
                else
                    $date = $year.'-'.$month.'-'.$start;
                $query1 = array(
                    'SUM(total_discount / exchange_rate) AS total_discount',
                    'SUM(order_discount / exchange_rate) AS order_discount',
                    'SUM(total_tax / exchange_rate) AS total_tax',
                    'SUM(order_tax / exchange_rate) AS order_tax',
                    'SUM(shipping_cost / exchange_rate) AS shipping_cost',
                    'SUM(grand_total / exchange_rate) AS grand_total'
                );
                $purchase_data = Purchase::whereDate('created_at', $date)->selectRaw(implode(',', $query1))->get();
                $total_discount[$start] = $purchase_data[0]->total_discount;
                $order_discount[$start] = $purchase_data[0]->order_discount;
                $total_tax[$start] = $purchase_data[0]->total_tax;
                $order_tax[$start] = $purchase_data[0]->order_tax;
                $shipping_cost[$start] = $purchase_data[0]->shipping_cost;
                $grand_total[$start] = $purchase_data[0]->grand_total;
                $start++;
            }
            $start_day = date('w', strtotime($year.'-'.$month.'-01')) + 1;
            $prev_year = date('Y', strtotime('-1 month', strtotime($year.'-'.$month.'-01')));
            $prev_month = date('m', strtotime('-1 month', strtotime($year.'-'.$month.'-01')));
            $next_year = date('Y', strtotime('+1 month', strtotime($year.'-'.$month.'-01')));
            $next_month = date('m', strtotime('+1 month', strtotime($year.'-'.$month.'-01')));
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            $warehouse_id = 0;
            return view('backend.report.daily_purchase', compact('total_discount','order_discount', 'total_tax', 'order_tax', 'shipping_cost', 'grand_total', 'start_day', 'year', 'month', 'number_of_day', 'prev_year', 'prev_month', 'next_year', 'next_month', 'lims_warehouse_list', 'warehouse_id'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function dailyPurchaseByWarehouse(Request $request, $year, $month)
    {
        $data = $request->all();
        if($data['warehouse_id'] == 0)
            return redirect()->back();
        $start = 1;
        $number_of_day = date('t', mktime(0, 0, 0, $month, 1, $year));
        while($start <= $number_of_day)
        {
            if($start < 10)
                $date = $year.'-'.$month.'-0'.$start;
            else
                $date = $year.'-'.$month.'-'.$start;
            $query1 = array(
                'SUM(total_discount / exchange_rate) AS total_discount',
                'SUM(order_discount / exchange_rate) AS order_discount',
                'SUM(total_tax / exchange_rate) AS total_tax',
                'SUM(order_tax / exchange_rate) AS order_tax',
                'SUM(shipping_cost / exchange_rate) AS shipping_cost',
                'SUM(grand_total / exchange_rate) AS grand_total'
            );
            $purchase_data = Purchase::where('warehouse_id', $data['warehouse_id'])->whereDate('created_at', $date)->selectRaw(implode(',', $query1))->get();
            $total_discount[$start] = $purchase_data[0]->total_discount;
            $order_discount[$start] = $purchase_data[0]->order_discount;
            $total_tax[$start] = $purchase_data[0]->total_tax;
            $order_tax[$start] = $purchase_data[0]->order_tax;
            $shipping_cost[$start] = $purchase_data[0]->shipping_cost;
            $grand_total[$start] = $purchase_data[0]->grand_total;
            $start++;
        }
        $start_day = date('w', strtotime($year.'-'.$month.'-01')) + 1;
        $prev_year = date('Y', strtotime('-1 month', strtotime($year.'-'.$month.'-01')));
        $prev_month = date('m', strtotime('-1 month', strtotime($year.'-'.$month.'-01')));
        $next_year = date('Y', strtotime('+1 month', strtotime($year.'-'.$month.'-01')));
        $next_month = date('m', strtotime('+1 month', strtotime($year.'-'.$month.'-01')));
        $lims_warehouse_list = Warehouse::where('is_active', true)->get();
        $warehouse_id = $data['warehouse_id'];

        return view('backend.report.daily_purchase', compact('total_discount','order_discount', 'total_tax', 'order_tax', 'shipping_cost', 'grand_total', 'start_day', 'year', 'month', 'number_of_day', 'prev_year', 'prev_month', 'next_year', 'next_month', 'lims_warehouse_list', 'warehouse_id'));
    }

    public function monthlySale($year)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('monthly-sale')){
            $start = strtotime($year .'-01-01');
            $end = strtotime($year .'-12-31');
            while($start <= $end)
            {
                $number_of_day = date('t', mktime(0, 0, 0, date('m', $start), 1, $year));
                $start_date = $year . '-'. date('m', $start).'-'.'01';
                $end_date = $year . '-'. date('m', $start).'-'.$number_of_day;

                $sale_q = Sale::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->whereNull('deleted_at');

                $temp_total_discount = $sale_q->sum(DB::raw('total_discount / exchange_rate'));
                $total_discount[] = number_format((float)$temp_total_discount, config('decimal'), '.', '');

                $temp_order_discount = $sale_q->sum(DB::raw('order_discount / exchange_rate'));
                $order_discount[] = number_format((float)$temp_order_discount, config('decimal'), '.', '');

                $temp_total_tax = $sale_q->sum(DB::raw('total_tax / exchange_rate'));
                $total_tax[] = number_format((float)$temp_total_tax, config('decimal'), '.', '');

                $temp_order_tax = $sale_q->sum(DB::raw('order_tax / exchange_rate'));
                $order_tax[] = number_format((float)$temp_order_tax, config('decimal'), '.', '');

                $temp_shipping_cost = $sale_q->sum(DB::raw('shipping_cost / exchange_rate'));
                $shipping_cost[] = number_format((float)$temp_shipping_cost, config('decimal'), '.', '');

                $temp_total = $sale_q->sum(DB::raw('grand_total / exchange_rate'));

                $total[] = number_format((float)$temp_total, config('decimal'), '.', '');

                $start = strtotime("+1 month", $start);
            }
            $lims_warehouse_list = Warehouse::where('is_active',true)->get();
            $warehouse_id = 0;
            return view('backend.report.monthly_sale', compact('year', 'total_discount', 'order_discount', 'total_tax', 'order_tax', 'shipping_cost', 'total', 'lims_warehouse_list', 'warehouse_id'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function monthlySaleByWarehouse(Request $request, $year)
    {
        $data = $request->all();
        if($data['warehouse_id'] == 0)
            return redirect()->back();

        $start = strtotime($year .'-01-01');
        $end = strtotime($year .'-12-31');
        while($start <= $end)
        {
            $number_of_day = date('t', mktime(0, 0, 0, date('m', $start), 1, $year));
            $start_date = $year . '-'. date('m', $start).'-'.'01';
            $end_date = $year . '-'. date('m', $start).'-'.$number_of_day;

            $sale_q = Sale::where('warehouse_id', $data['warehouse_id'])->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->whereNull('deleted_at');

            $temp_total_discount = $sale_q->sum(DB::raw('total_discount / exchange_rate'));
            $total_discount[] = number_format((float)$temp_total_discount, config('decimal'), '.', '');

            $temp_order_discount = $sale_q->sum(DB::raw('order_discount / exchange_rate'));
            $order_discount[] = number_format((float)$temp_order_discount, config('decimal'), '.', '');

            $temp_total_tax = $sale_q->sum(DB::raw('total_tax / exchange_rate'));
            $total_tax[] = number_format((float)$temp_total_tax, config('decimal'), '.', '');

            $temp_order_tax = $sale_q->sum(DB::raw('order_tax / exchange_rate'));
            $order_tax[] = number_format((float)$temp_order_tax, config('decimal'), '.', '');

            $temp_shipping_cost = $sale_q->sum(DB::raw('shipping_cost / exchange_rate'));
            $shipping_cost[] = number_format((float)$temp_shipping_cost, config('decimal'), '.', '');

            $temp_total = $sale_q->sum(DB::raw('grand_total / exchange_rate'));
            $total[] = number_format((float)$temp_total, config('decimal'), '.', '');
            $start = strtotime("+1 month", $start);
        }
        $lims_warehouse_list = Warehouse::where('is_active',true)->get();
        $warehouse_id = $data['warehouse_id'];
        return view('backend.report.monthly_sale', compact('year', 'total_discount', 'order_discount', 'total_tax', 'order_tax', 'shipping_cost', 'total', 'lims_warehouse_list', 'warehouse_id'));
    }

    public function monthlyPurchase($year)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('monthly-purchase')){
            $start = strtotime($year .'-01-01');
            $end = strtotime($year .'-12-31');
            while($start <= $end)
            {
                $number_of_day = date('t', mktime(0, 0, 0, date('m', $start), 1, $year));
                $start_date = $year . '-'. date('m', $start).'-'.'01';
                $end_date = $year . '-'. date('m', $start).'-'.$number_of_day;

                $query1 = array(
                    'SUM(total_discount / exchange_rate) AS total_discount',
                    'SUM(order_discount / exchange_rate) AS order_discount',
                    'SUM(total_tax / exchange_rate) AS total_tax',
                    'SUM(order_tax / exchange_rate) AS order_tax',
                    'SUM(shipping_cost / exchange_rate) AS shipping_cost',
                    'SUM(grand_total / exchange_rate) AS grand_total'
                );
                $purchase_data = Purchase::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query1))->get();

                $total_discount[] = number_format((float)$purchase_data[0]->total_discount, config('decimal'), '.', '');
                $order_discount[] = number_format((float)$purchase_data[0]->order_discount, config('decimal'), '.', '');
                $total_tax[] = number_format((float)$purchase_data[0]->total_tax, config('decimal'), '.', '');
                $order_tax[] = number_format((float)$purchase_data[0]->order_tax, config('decimal'), '.', '');
                $shipping_cost[] = number_format((float)$purchase_data[0]->shipping_cost, config('decimal'), '.', '');
                $grand_total[] = number_format((float)$purchase_data[0]->grand_total, config('decimal'), '.', '');
                $start = strtotime("+1 month", $start);
            }
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            $warehouse_id = 0;
            return view('backend.report.monthly_purchase', compact('year', 'total_discount', 'order_discount', 'total_tax', 'order_tax', 'shipping_cost', 'grand_total', 'lims_warehouse_list', 'warehouse_id'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function monthlyPurchaseByWarehouse(Request $request, $year)
    {
        $data = $request->all();
        if($data['warehouse_id'] == 0)
            return redirect()->back();

        $start = strtotime($year .'-01-01');
        $end = strtotime($year .'-12-31');
        while($start <= $end)
        {
            $number_of_day = date('t', mktime(0, 0, 0, date('m', $start), 1, $year));
            $start_date = $year . '-'. date('m', $start).'-'.'01';
            $end_date = $year . '-'. date('m', $start).'-'.$number_of_day;

            $query1 = array(
                'SUM(total_discount / exchange_rate) AS total_discount',
                'SUM(order_discount / exchange_rate) AS order_discount',
                'SUM(total_tax / exchange_rate) AS total_tax',
                'SUM(order_tax / exchange_rate) AS order_tax',
                'SUM(shipping_cost / exchange_rate) AS shipping_cost',
                'SUM(grand_total / exchange_rate) AS grand_total'
            );
            $purchase_data = Purchase::where('warehouse_id', $data['warehouse_id'])->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query1))->get();

            $total_discount[] = number_format((float)$purchase_data[0]->total_discount, config('decimal'), '.', '');
            $order_discount[] = number_format((float)$purchase_data[0]->order_discount, config('decimal'), '.', '');
            $total_tax[] = number_format((float)$purchase_data[0]->total_tax, config('decimal'), '.', '');
            $order_tax[] = number_format((float)$purchase_data[0]->order_tax, config('decimal'), '.', '');
            $shipping_cost[] = number_format((float)$purchase_data[0]->shipping_cost, config('decimal'), '.', '');
            $grand_total[] = number_format((float)$purchase_data[0]->grand_total, config('decimal'), '.', '');
            $start = strtotime("+1 month", $start);
        }
        $lims_warehouse_list = Warehouse::where('is_active', true)->get();
        $warehouse_id = $data['warehouse_id'];
        return view('backend.report.monthly_purchase', compact('year', 'total_discount', 'order_discount', 'total_tax', 'order_tax', 'shipping_cost', 'grand_total', 'lims_warehouse_list', 'warehouse_id'));
    }

    public function bestSeller()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('best-seller')){
            $start = strtotime(date("Y-m", strtotime("-2 months")).'-01');
            $end = strtotime(date("Y").'-'.date("m").'-31');

            while($start <= $end)
            {
                $number_of_day = date('t', mktime(0, 0, 0, date('m', $start), 1, date('Y', $start)));
                $start_date = date("Y-m", $start).'-'.'01';
                $end_date = date("Y-m", $start).'-'.$number_of_day;

                $best_selling_qty = Product_Sale::select(DB::raw('product_id, sum(qty) as sold_qty'))->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->groupBy('product_id')->orderBy('sold_qty', 'desc')->take(1)->get();
                if(!count($best_selling_qty)){
                    $product[] = '';
                    $sold_qty[] = 0;
                }
                foreach ($best_selling_qty as $best_seller) {
                    $product_data = Product::find($best_seller->product_id);
                    $product[] = $product_data->name.': '.$product_data->code;
                    $sold_qty[] = $best_seller->sold_qty;
                }
                $start = strtotime("+1 month", $start);
            }
            $start_month = date("F Y", strtotime('-2 month'));
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            $warehouse_id = 0;
            //return $product;
            return view('backend.report.best_seller', compact('product', 'sold_qty', 'start_month', 'lims_warehouse_list', 'warehouse_id'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function bestSellerByWarehouse(Request $request)
    {
        $data = $request->all();
        if($data['warehouse_id'] == 0)
            return redirect()->back();

        $start = strtotime(date("Y-m", strtotime("-2 months")).'-01');
        $end = strtotime(date("Y").'-'.date("m").'-31');

        while($start <= $end)
        {
            $number_of_day = date('t', mktime(0, 0, 0, date('m', $start), 1, date('Y', $start)));
            $start_date = date("Y-m", $start).'-'.'01';
            $end_date = date("Y-m", $start).'-'.$number_of_day;

            $best_selling_qty = DB::table('sales')
                                ->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')->select(DB::raw('product_sales.product_id, sum(product_sales.qty) as sold_qty'))->where('sales.warehouse_id', $data['warehouse_id'])->whereNull('sales.deleted_at')->whereDate('sales.created_at', '>=' , $start_date)->whereDate('sales.created_at', '<=' , $end_date)->groupBy('product_id')->orderBy('sold_qty', 'desc')->take(1)->get();

            if(!count($best_selling_qty)) {
                $product[] = '';
                $sold_qty[] = 0;
            }
            foreach ($best_selling_qty as $best_seller) {
                $product_data = Product::find($best_seller->product_id);
                $product[] = $product_data->name.': '.$product_data->code;
                $sold_qty[] = $best_seller->sold_qty;
            }
            $start = strtotime("+1 month", $start);
        }
        $start_month = date("F Y", strtotime('-2 month'));
        $lims_warehouse_list = Warehouse::where('is_active', true)->get();
        $warehouse_id = $data['warehouse_id'];
        return view('backend.report.best_seller', compact('product', 'sold_qty', 'start_month', 'lims_warehouse_list', 'warehouse_id'));
    }

    /** Sidebar links open these reports by GET with no filters: default to month to date. */
    private function reportDefaults(Request $request, array $extra = []): void
    {
        $request->mergeIfMissing(['start_date' => date('Y-m-01'), 'end_date' => date('Y-m-d')] + $extra);
    }

    public function calculateAverageCOGS($product_sale_data)
    {
        $product_cost = 0;
        $product_tax = 0;
        foreach ($product_sale_data as $key => $product_sale) {
            $product_data = Product::select('type', 'product_list', 'variant_list', 'qty_list')->find($product_sale->product_id);
            if($product_data->type == 'combo') {
                $product_list = explode(",", $product_data->product_list);
                if($product_data->variant_list)
                    $variant_list = explode(",", $product_data->variant_list);
                else
                    $variant_list = [];
                $qty_list = explode(",", $product_data->qty_list);

                foreach ($product_list as $index => $product_id) {
                    if(count($variant_list) && $variant_list[$index]) {
                        $product_purchase_data = ProductPurchase::join('purchases', 'product_purchases.purchase_id', '=', 'purchases.id')
                        ->where([
                            ['product_purchases.product_id', $product_id],
                            ['product_purchases.variant_id', $variant_list[$index] ]
                        ])
                        ->whereNull('purchases.deleted_at')
                        ->select('purchases.exchange_rate', 'product_purchases.recieved', 'product_purchases.purchase_unit_id', 'product_purchases.tax', 'product_purchases.total')
                        ->get();
                    }
                    else {
                        $product_purchase_data = ProductPurchase::join('purchases', 'product_purchases.purchase_id', '=', 'purchases.id')
                        ->where('product_purchases.product_id', $product_id)
                        ->whereNull('purchases.deleted_at')
                        ->select('purchases.exchange_rate', 'product_purchases.recieved', 'product_purchases.purchase_unit_id', 'product_purchases.tax', 'product_purchases.total')
                        ->get();
                    }
                    $total_received_qty = 0;
                    $total_purchased_amount = 0;
                    $total_tax = 0;
                    $sold_qty = ($product_sale->sold_qty - $product_sale->return_qty) * $qty_list[$index];
                    foreach ($product_purchase_data as $key => $product_purchase) {
                        $purchase_unit_data = Unit::select('operator', 'operation_value')->find($product_purchase->purchase_unit_id);
                        if($purchase_unit_data->operator == '*')
                            $total_received_qty += $product_purchase->recieved * $purchase_unit_data->operation_value;
                        else
                            $total_received_qty += $product_purchase->recieved / $purchase_unit_data->operation_value;

                        $total_purchased_amount += $product_purchase->total / 
                        (($product_purchase->exchange_rate && $product_purchase->exchange_rate != 0) ? $product_purchase->exchange_rate : 1);

                        $total_tax += $product_purchase->tax / 
                        (($product_purchase->exchange_rate && $product_purchase->exchange_rate != 0) ? $product_purchase->exchange_rate : 1);
                    }
                    if($total_received_qty) {
                        $averageCost = $total_purchased_amount / $total_received_qty;
                        $averageTax = $total_tax / $total_received_qty;
                    }
                    else {
                        $averageCost = 0;
                        $averageTax = 0;
                    }
                    $product_cost += $sold_qty * $averageCost;
                    $product_tax += $sold_qty * $averageTax;
                }
            }
            else {
                if($product_sale->product_batch_id) {
                    $product_purchase_data = ProductPurchase::join('purchases', 'product_purchases.purchase_id', '=', 'purchases.id')
                        ->where([
                        ['product_purchases.product_id', $product_sale->product_id],
                        ['product_purchases.product_batch_id', $product_sale->product_batch_id]
                    ])
                    ->whereNull('purchases.deleted_at')
                    ->select('purchases.exchange_rate', 'product_purchases.recieved', 'product_purchases.purchase_unit_id', 'product_purchases.tax', 'product_purchases.total')
                    ->get();
                }
                elseif($product_sale->variant_id) {
                    $product_purchase_data = ProductPurchase::join('purchases', 'product_purchases.purchase_id', '=', 'purchases.id')
                        ->where([
                        ['product_purchases.product_id', $product_sale->product_id],
                        ['product_purchases.variant_id', $product_sale->variant_id]
                    ])
                    ->whereNull('purchases.deleted_at')
                    ->select('purchases.exchange_rate', 'product_purchases.recieved', 'product_purchases.purchase_unit_id', 'product_purchases.tax', 'product_purchases.total')
                    ->get();
                }
                else {
                    $product_purchase_data = ProductPurchase::join('purchases', 'product_purchases.purchase_id', '=', 'purchases.id')
                        ->where('product_id', $product_sale->product_id)
                        ->whereNull('purchases.deleted_at')
                    ->select('purchases.exchange_rate', 'product_purchases.recieved', 'product_purchases.purchase_unit_id', 'product_purchases.tax', 'product_purchases.total', 'purchases.exchange_rate')
                    ->get();
                }
                $total_received_qty = 0;
                $total_purchased_amount = 0;
                $total_tax = 0;
                if($product_sale->sale_unit_id) {
                    $sale_unit_data = Unit::select('operator', 'operation_value')->find($product_sale->sale_unit_id);
                    if($sale_unit_data->operator == '*')
                        $sold_qty = ($product_sale->sold_qty - $product_sale->return_qty) * $sale_unit_data->operation_value;
                    else
                        $sold_qty = ($product_sale->sold_qty - $product_sale->return_qty) / $sale_unit_data->operation_value;
                }
                else {
                    $sold_qty = ($product_sale->sold_qty - $product_sale->return_qty);
                }
                foreach ($product_purchase_data as $key => $product_purchase) {
                    $purchase_unit_data = Unit::select('operator', 'operation_value')->find($product_purchase->purchase_unit_id);
                    if($purchase_unit_data) {
                        if($purchase_unit_data->operator == '*')
                            $total_received_qty += $product_purchase->recieved * $purchase_unit_data->operation_value;
                        else
                            $total_received_qty += $product_purchase->recieved / $purchase_unit_data->operation_value;

                        $total_purchased_amount += $product_purchase->total / 
                        (($product_purchase->exchange_rate && $product_purchase->exchange_rate != 0) ? $product_purchase->exchange_rate : 1);

                        $total_tax += $product_purchase->tax / 
                        (($product_purchase->exchange_rate && $product_purchase->exchange_rate != 0) ? $product_purchase->exchange_rate : 1);
                    }
                }
                if($total_received_qty) {
                    $averageCost = $total_purchased_amount / $total_received_qty;
                    $averageTax = $total_tax / $total_received_qty;
                }
                else {
                    $averageCost = 0;
                    $averageTax = 0;
                }
                $product_cost += $sold_qty * $averageCost;
                $product_tax += $sold_qty * $averageTax;
            }
        }
        return [$product_cost, $product_tax];
    }

    public function productReport(Request $request)
    {
        $data = $request->all();
        // Opened from a plain link (no filter submitted yet): this month, all warehouses.
        $start_date = $data['start_date'] ?? date('Y-m').'-01';
        $end_date = $data['end_date'] ?? date('Y-m-d');
        $warehouse_id = $data['warehouse_id'] ?? 0;
        $category_id = $data['category_id'] ?? 0;
        $lims_warehouse_list = Warehouse::where('is_active', true)->get();
        return view('backend.report.product_report',compact('start_date', 'end_date', 'warehouse_id', 'category_id', 'lims_warehouse_list'));
    }

    public function productReportData(Request $request)
    {
        // --- Input & safe defaults
        $start_date = $request->input('start_date') . ' 00:00:00';
        $end_date   = $request->input('end_date') . ' 23:59:59';
        $warehouse_id = (int) $request->input('warehouse_id', 0);
        $category_id = (int) $request->input('category_id', 0);
        $search       = $request->input('search.value', null);
        $draw         = (int) $request->input('draw', 1);
        $limit        = $request->input('length') != -1 ? (int)$request->input('length') : 1000;
        $start        = (int) $request->input('start', 0);
        $orderColumn  = $request->input('order.0.column');
        $columns = [1 => 'name']; // keep mapping consistent with your frontend
        $order = $columns[$orderColumn] ?? 'name';
        $dir   = $request->input('order.0.dir', 'asc');

        // --- Preload small reference tables once
        $units = DB::table('units')->get()->keyBy('id')->toArray(); // cached units

        // helper for unit conversion (keep it local)
        $convertQty = function ($qty, $unitId) use ($units) {
            if (!$unitId || !isset($units[$unitId])) {
                return (float)$qty;
            }
            $unit = $units[$unitId];
            if ($unit->operator === '*') {
                return (float)$qty * (float)$unit->operation_value;
            }
            return (float)$qty / (float)$unit->operation_value;
        };

        // --- 1) Build list of product IDs that have ANY stock (>0)
        $stockQuery = DB::table('product_warehouse')
            ->selectRaw('product_id, COALESCE(variant_id, 0) as variant_id, SUM(qty) as total_qty')
            ->groupBy('product_id', DB::raw('COALESCE(variant_id, 0)'));

        // Apply warehouse-specific filter ONLY when $warehouse_id != 0
        if ($warehouse_id != 0) {
            $stockQuery->where('warehouse_id', $warehouse_id);
        }

        // Only include products that have POSITIVE stock in that warehouse
        $stockRows = $stockQuery->havingRaw('SUM(qty) > 0')->get();

        // Build maps for stock:
        // stocksByProduct[product_id]['variants'][variant_id] = qty
        // stocksByProduct[product_id]['total'] = total qty across variants
        $stocksByProduct = [];
        foreach ($stockRows as $r) {
            $pid = (int)$r->product_id;
            $vid = (int)$r->variant_id;
            $qty = (float)$r->total_qty;
            if (!isset($stocksByProduct[$pid])) $stocksByProduct[$pid] = ['variants' => [], 'total' => 0.0];
            $stocksByProduct[$pid]['variants'][$vid] = $qty;
            $stocksByProduct[$pid]['total'] += $qty;
        }

        // If no product has stock, return empty result early
        if (empty($stocksByProduct)) {
            return response()->json([
                'draw' => $draw,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => []
            ]);
        }

        $productIdsWithStock = array_keys($stocksByProduct);

        // --- 2) DataTables light query: count, filtered ids & page
        $productsBaseQuery = Product::with('category')
            ->select('id', 'name', 'code', 'category_id', 'qty', 'is_variant', 'price', 'cost')
            ->where('is_active', true)
            ->whereIn('id', $productIdsWithStock);

        if ($search) {
            $productsBaseQuery->where('name', 'LIKE', "%{$search}%");
        }

        // total count (filtered)
        $totalFiltered = $productsBaseQuery->count();
        $totalData = $productsBaseQuery->count(); // same as filtered unless you have separate logic

        // fetch only product ids for the current page (lightweight)
        $pagedProductIds = $productsBaseQuery
            ->orderBy($order, $dir)
            ->offset($start)
            ->limit($limit)
            ->pluck('id')
            ->toArray();

        if (empty($pagedProductIds)) {
            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $totalData,
                'recordsFiltered' => $totalFiltered,
                'data' => []
            ]);
        }

        // --- 3) Load full product models (with category) for the paged ids
        $products = Product::with('category')
            ->select('id', 'name', 'code', 'category_id', 'qty', 'is_variant', 'price', 'cost')
            ->whereIn('id', $pagedProductIds)
            ->when($category_id > 0, fn($q) => $q->where('category_id', $category_id))
            ->get()
            ->keyBy('id');

        // --- 4) Load product variants for these products (if any) — we'll use variant qty from ProductVariant->qty for global
        $productVariants = ProductVariant::whereIn('product_id', $pagedProductIds)
            ->select('product_id', 'variant_id', 'item_code', 'qty')
            ->get()
            ->groupBy('product_id');

        // helper map accessors to check stock for variant/global
        // For variant-level stock, prefer product_warehouse aggregate if available, else ProductVariant.qty
        // stocksByProduct might contain variant entries from product_warehouse (global sums)
        // productVariants map gives per-variant qty from ProductVariant table (non-warehouse)
        // We'll use stocksByProduct first (since it's grouped from product_warehouse), otherwise fallback.

        // --- 5) Load aggregated transactional data in batches (grouped)
        // Purchases (product_purchases JOIN purchases) => amount (total/exchange_rate) and qty grouped by unit id to convert later
        $purchaseRows = DB::table('purchases')
            ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')
            ->when($warehouse_id > 0, function ($q) use ($warehouse_id) {
                return $q->where('purchases.warehouse_id', $warehouse_id);
            })
            ->whereNull('purchases.deleted_at')
            ->whereBetween(DB::raw('DATE(purchases.created_at)'), [$start_date, $end_date])
            ->whereIn('product_purchases.product_id', $pagedProductIds)
            ->selectRaw('product_purchases.product_id, COALESCE(product_purchases.variant_id, 0) as variant_id, product_purchases.purchase_unit_id as unit_id, SUM(product_purchases.qty) as qty_sum, SUM(product_purchases.total / purchases.exchange_rate) as amount_sum')
            ->groupBy('product_purchases.product_id', 'variant_id', 'product_purchases.purchase_unit_id')
            ->get();

        // Sales
        $saleRows = DB::table('sales')
            ->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')
            ->when($warehouse_id > 0, function ($q) use ($warehouse_id) {
                return $q->where('sales.warehouse_id', $warehouse_id);
            })
            ->whereNull('sales.deleted_at')
            ->whereBetween(DB::raw('DATE(sales.created_at)'), [$start_date, $end_date])
            ->whereIn('product_sales.product_id', $pagedProductIds)
            ->selectRaw('product_sales.product_id, COALESCE(product_sales.variant_id, 0) as variant_id, product_sales.sale_unit_id as unit_id, SUM(product_sales.qty) as qty_sum, SUM(product_sales.total / sales.exchange_rate) as amount_sum')
            ->groupBy('product_sales.product_id', 'variant_id', 'product_sales.sale_unit_id')
            ->get();

        // Product returns (returns join product_returns)
        $returnRows = DB::table('returns')
            ->join('product_returns', 'returns.id', '=', 'product_returns.return_id')
            ->when($warehouse_id > 0, function ($q) use ($warehouse_id) {
                return $q->where('returns.warehouse_id', $warehouse_id);
            })
            ->whereBetween(DB::raw('DATE(returns.created_at)'), [$start_date, $end_date])
            ->whereIn('product_returns.product_id', $pagedProductIds)
            ->selectRaw('product_returns.product_id, COALESCE(product_returns.variant_id, 0) as variant_id, product_returns.sale_unit_id as unit_id, SUM(product_returns.qty) as qty_sum, SUM(product_returns.total / returns.exchange_rate) as amount_sum')
            ->groupBy('product_returns.product_id', 'variant_id', 'product_returns.sale_unit_id')
            ->get();

        // Purchase returns (return_purchases join purchase_product_return)
        $purchaseReturnRows = DB::table('return_purchases')
            ->join('purchase_product_return', 'return_purchases.id', '=', 'purchase_product_return.return_id')
            ->when($warehouse_id > 0, function ($q) use ($warehouse_id) {
                return $q->where('return_purchases.warehouse_id', $warehouse_id);
            })
            ->whereBetween(DB::raw('DATE(return_purchases.created_at)'), [$start_date, $end_date])
            ->whereIn('purchase_product_return.product_id', $pagedProductIds)
            ->selectRaw('purchase_product_return.product_id, COALESCE(purchase_product_return.variant_id, 0) as variant_id, purchase_product_return.purchase_unit_id as unit_id, SUM(purchase_product_return.qty) as qty_sum, SUM(purchase_product_return.total / return_purchases.exchange_rate) as amount_sum')
            ->groupBy('purchase_product_return.product_id', 'variant_id', 'purchase_product_return.purchase_unit_id')
            ->get();

        // Build aggregated maps: amounts and converted qtys per product+variant
        $aggregate = function ($rows) use ($convertQty) {
            $map = [];
            foreach ($rows as $r) {
                $pid = (int)$r->product_id;
                $vid = (int)$r->variant_id;
                $unitId = $r->unit_id;
                $qtySum = (float)$r->qty_sum;
                $amountSum = (float)$r->amount_sum;

                if (!isset($map[$pid])) $map[$pid] = [];
                if (!isset($map[$pid][$vid])) $map[$pid][$vid] = ['amount' => 0.0, 'qty' => 0.0];

                $map[$pid][$vid]['amount'] += $amountSum;
                // Convert qty per unit rules
                $map[$pid][$vid]['qty'] += $convertQty($qtySum, $unitId);
            }
            return $map;
        };

        $purchasesMap = $aggregate($purchaseRows);
        $salesMap = $aggregate($saleRows);
        $returnsMap = $aggregate($returnRows);
        $purchaseReturnsMap = $aggregate($purchaseReturnRows);

        // --- 6) Build final rows
        $data = [];

        foreach ($products as $product) {
            $pid = $product->id;
            $isVariant = (bool)$product->is_variant;

            // Function to fetch aggregated values with safe defaults
            $getAgg = function ($map, $pid, $vid) {
                return $map[$pid][$vid] ?? ['amount' => 0.0, 'qty' => 0.0];
            };

            if ($isVariant) {
                // iterate over variants for this product
                $variants = $productVariants->get($pid) ?? collect([]);
                foreach ($variants as $variant) {
                    $vid = (int)$variant->variant_id;
                    $itemCode = $variant->item_code ?? $variant->id;

                    // Determine in_stock
                    $inStock = 0;
                    if (isset($stocksByProduct[$pid]['variants'][$vid])) {
                        $inStock = (float)$stocksByProduct[$pid]['variants'][$vid];
                    } else {
                        // fallback to ProductVariant.qty
                        $inStock = (float)$variant->qty;
                    }

                    // skip rows with zero stock (preserves your previous behavior)
                    if ($inStock <= 0) continue;

                    // aggregated numbers
                    $purchased = $getAgg($purchasesMap, $pid, $vid);
                    $sold      = $getAgg($salesMap, $pid, $vid);
                    $returned  = $getAgg($returnsMap, $pid, $vid);
                    $purchaseReturned = $getAgg($purchaseReturnsMap, $pid, $vid);

                    $nested = [];
                    $nested['imei_numbers'] = $this->findImeis($pid, $vid);
                    $nested['key'] = count($data);
                    $nested['name'] = $product->name . ' [' . (Variant::select('name')->find($vid)->name ?? 'N/A') . ']' . '<br/>Product Code: ' . $itemCode;
                    $nested['category'] = optional($product->category)->name;

                    $nested['purchased_amount'] = $purchased['amount'];
                    $nested['purchased_qty'] = $purchased['qty'];

                    $nested['sold_amount'] = $sold['amount'];
                    $nested['sold_qty'] = $sold['qty'];

                    $nested['returned_amount'] = $returned['amount'];
                    $nested['returned_qty'] = $returned['qty'];

                    $nested['purchase_returned_amount'] = $purchaseReturned['amount'];
                    $nested['purchase_returned_qty'] = $purchaseReturned['qty'];

                    // profit calculation (same logic you had)
                    if ($nested['purchased_qty'] > 0) {
                        $nested['profit'] = $nested['sold_amount'] - (($nested['purchased_amount'] / $nested['purchased_qty']) * $nested['sold_qty']);
                    } else {
                        $nested['profit'] = $nested['sold_amount'];
                    }

                    $nested['in_stock'] = $inStock;
                    if (config('currency_position') == 'prefix') {
                        $nested['stock_worth'] = config('currency').' '.($nested['in_stock'] * $product->price).' / '.config('currency').' '.($nested['in_stock'] * $product->cost);
                    } else {
                        $nested['stock_worth'] = ($nested['in_stock'] * $product->price).' '.config('currency').' / '.($nested['in_stock'] * $product->cost).' '.config('currency');
                    }
                    $nested['profit'] = number_format((float)$nested['profit'], config('decimal'), '.', '');

                    $data[] = $nested;
                }
            } else {
                // non-variant product
                // Determine in_stock: from product_warehouse group or product->qty fallback
                $inStock = $stocksByProduct[$pid]['total'] ?? (float)$product->qty;

                if ($inStock <= 0) continue;

                $purchased = $getAgg($purchasesMap, $pid, 0);
                $sold = $getAgg($salesMap, $pid, 0);
                $returned = $getAgg($returnsMap, $pid, 0);
                $purchaseReturned = $getAgg($purchaseReturnsMap, $pid, 0);

                $nested = [];
                $nested['imei_numbers'] = $this->findImeis($pid);
                $nested['key'] = count($data);
                $nested['name'] = $product->name . '<br/>Product Code: ' . $product->code;
                $nested['category'] = optional($product->category)->name;

                $nested['purchased_amount'] = $purchased['amount'];
                $nested['purchased_qty'] = $purchased['qty'];

                $nested['sold_amount'] = $sold['amount'];
                $nested['sold_qty'] = $sold['qty'];

                $nested['returned_amount'] = $returned['amount'];
                $nested['returned_qty'] = $returned['qty'];

                $nested['purchase_returned_amount'] = $purchaseReturned['amount'];
                $nested['purchase_returned_qty'] = $purchaseReturned['qty'];

                if ($nested['purchased_qty'] > 0) {
                    $nested['profit'] = $nested['sold_amount'] - (($nested['purchased_amount'] / $nested['purchased_qty']) * $nested['sold_qty']);
                } else {
                    $nested['profit'] = $nested['sold_amount'];
                }

                $nested['in_stock'] = $inStock;
                if (config('currency_position') == 'prefix') {
                    $nested['stock_worth'] = config('currency').' '.($nested['in_stock'] * $product->price).' / '.config('currency').' '.($nested['in_stock'] * $product->cost);
                } else {
                    $nested['stock_worth'] = ($nested['in_stock'] * $product->price).' '.config('currency').' / '.($nested['in_stock'] * $product->cost).' '.config('currency');
                }
                $nested['profit'] = number_format((float)$nested['profit'], config('decimal'), '.', '');

                $data[] = $nested;
            }
        }

        // --- 7) Return DataTables JSON
        return response()->json([
            'draw' => $draw,
            'recordsTotal' => (int)$totalData,
            'recordsFiltered' => (int)$totalFiltered,
            'data' => $data
        ]);
    }

    private function findImeis(string $product_id, string $variant_id = '0')
    {
        $imei_numbers = [];
        $purchases = [];
        if ($variant_id === '0') {
            $purchases = Product_Warehouse::where('product_id', $product_id)
                ->whereNotNull('imei_number')
                ->select('imei_number')->get();
        } else {
            $purchases = Product_Warehouse::where('product_id', $product_id)
                ->where('variant_id', '=', $variant_id)
                ->whereNotNull('imei_number')
                ->select('imei_number')->get();
        }
        
        foreach ($purchases as $purchase) {
            $imei_numbers[] = array_unique(explode(',', $purchase->imei_number));
        }
        $imeis = [];
        foreach ($imei_numbers as $imei_number) {
            foreach ($imei_number as $imei) {
                if ($imei != 'null')
                    $imeis[] = $imei;
            }
        }

        $convert_to_string = '';
        foreach ($imeis as $key => $value) {
            $convert_to_string .= $value;
            if (count($imeis)-1 > $key) {
                $convert_to_string .= '<br/>';
            }
        }

        if (empty($convert_to_string)) {
            return 'N/A';
        }
        return $convert_to_string;
    }

    public function purchaseReport(Request $request)
    {
        $this->reportDefaults($request, ['warehouse_id' => 0]);
        $data = $request->all();
        $start_date = $data['start_date'];
        $end_date = $data['end_date'];
        $warehouse_id = $data['warehouse_id'];
        $category_id = $data['category_id'] ?? 0;
        $product_id = [];
        $variant_id = [];
        $product_name = [];
        $product_qty = [];
        $lims_product_all = Product::select('id', 'name', 'qty', 'is_variant')->where('is_active', true)->get();
        foreach ($lims_product_all as $product) {
            $lims_product_purchase_data = null;
            $variant_id_all = [];
            if($warehouse_id == 0) {
                if($product->is_variant)
                    $variant_id_all = ProductPurchase::distinct('variant_id')->where('product_id', $product->id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->pluck('variant_id');
                else
                    $lims_product_purchase_data = ProductPurchase::where('product_id', $product->id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->first();
            }
            else {
                if($product->is_variant)
                    $variant_id_all = DB::table('purchases')
                        ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')
                        ->distinct('variant_id')
                        ->where([
                            ['product_purchases.product_id', $product->id],
                            ['purchases.warehouse_id', $warehouse_id]
                        ])->whereNull('purchases.deleted_at')
                        ->whereDate('purchases.created_at','>=', $start_date)
                          ->whereDate('purchases.created_at','<=', $end_date)
                          ->pluck('variant_id');
                else
                    $lims_product_purchase_data = DB::table('purchases')
                        ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')->where([
                                ['product_purchases.product_id', $product->id],
                                ['purchases.warehouse_id', $warehouse_id]
                        ])->whereNull('purchases.deleted_at')
                        ->whereDate('purchases.created_at','>=', $start_date)
                          ->whereDate('purchases.created_at','<=', $end_date)
                          ->first();
            }

            if($lims_product_purchase_data) {
                $product_name[] = $product->name;
                $product_id[] = $product->id;
                $variant_id[] = null;
                if($warehouse_id == 0)
                    $product_qty[] = $product->qty;
                else
                    $product_qty[] = Product_Warehouse::where([
                                    ['product_id', $product->id],
                                    ['warehouse_id', $warehouse_id]
                                ])->sum('qty');
            }
            elseif(count($variant_id_all)) {
                foreach ($variant_id_all as $key => $variantId) {
                    $variant_data = Variant::find($variantId);
                    $product_name[] = $product->name.' ['.$variant_data->name.']';
                    $product_id[] = $product->id;
                    $variant_id[] = $variant_data->id;
                    if($warehouse_id == 0)
                        $product_qty[] = ProductVariant::FindExactProduct($product->id, $variant_data->id)->first()->qty;
                    else
                        $product_qty[] = Product_Warehouse::where([
                                        ['product_id', $product->id],
                                        ['variant_id', $variant_data->id],
                                        ['warehouse_id', $warehouse_id]
                                    ])->first()->qty;

                }
            }
        }
        $lims_warehouse_list = Warehouse::where('is_active', true)->get();
        return view('backend.report.purchase_report',compact('product_id', 'variant_id', 'product_name', 'product_qty', 'start_date', 'end_date', 'lims_warehouse_list', 'warehouse_id', 'category_id'));
    }

    public function purchaseReportData(Request $request)
    {
        $data = $request->all();
        $start_date = $data['start_date'] . ' 00:00:00';
        $end_date = $data['end_date'] . ' 23:59:59';
        $warehouse_id = $data['warehouse_id'];
        $category_id = $data['category_id'];
        $product_id = [];
        $variant_id = [];
        $product_name = [];
        $product_qty = [];

        $columns = array(
            1 => 'name',
            2 => 'category_id',
            5 => 'qty'
        );

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        //return $request;
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')] ?? 'id';
        $dir = $request->input('order.0.dir');
        if($request->input('search.value')) {
            $search = $request->input('search.value');
            $totalData = Product::where('is_active', true)
            ->when($category_id > 0, fn($q) => $q->where('category_id', $category_id))
            ->where('name', 'LIKE', "%{$search}%")
            ->count();
            $lims_product_all = Product::with('category')
                                ->select('id', 'name', 'code', 'category_id', 'qty', 'is_variant', 'price', 'cost')
                                ->when($category_id > 0, fn($q) => $q->where('category_id', $category_id))
                                ->where([
                                    ['name', 'LIKE', "%{$search}%"],
                                    ['is_active', true]
                                ])->offset($start)
                                  ->limit($limit)
                                  ->orderBy($order, $dir)
                                  ->get();
        }
        else {
            $totalData = Product::where('is_active', true)
            ->when($category_id > 0, fn($q) => $q->where('category_id', $category_id))
            ->count();
            $lims_product_all = Product::with('category')
                                ->select('id', 'name', 'code', 'category_id', 'qty', 'is_variant', 'price', 'cost')
                                ->when($category_id > 0, fn($q) => $q->where('category_id', $category_id))
                                ->where('is_active', true)
                                ->offset($start)
                                ->limit($limit)
                                ->orderBy($order, $dir)
                                ->get();
        }

        $totalFiltered = $totalData;
        $data = [];
        foreach ($lims_product_all as $product) {
            $variant_id_all = [];
            if($warehouse_id == 0) {
                if($product->is_variant) {
                    $variant_id_all = ProductVariant::where('product_id', $product->id)->pluck('variant_id', 'item_code');
                    foreach ($variant_id_all as $item_code => $variant_id) {
                        $variant_data = Variant::select('name')->find($variant_id);
                        $nestedData['key'] = count($data);
                        $imeis = $this->findImeis($product->id, $variant_id);
                        $nestedData['name'] = $product->name . ' [' . $variant_data->name . ']'.'<br>'. 'Product Code: ' . $item_code . ($imeis != 'N/A' ? '<br>' . 'IMEI: ' . str_replace("<br/>", ",", $imeis) : '');
                        $nestedData['category'] = $product->category->name;
                        //purchase data
                        $nestedData['purchased_amount'] = DB::table('purchases')
                                                            ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')->where([
                                                                ['product_purchases.product_id', $product->id],
                                                                ['product_purchases.variant_id', $variant_id],
                                                            ])->whereNull('purchases.deleted_at')
                                                            ->whereDate('purchases.created_at','>=', $start_date)->whereDate('purchases.created_at','<=', $end_date)->sum(DB::raw('product_purchases.total / purchases.exchange_rate'));

                        $lims_product_purchase_data = ProductPurchase::select('purchase_unit_id', 'qty')->where([
                                                ['product_id', $product->id],
                                                ['variant_id', $variant_id]
                                        ])->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->get();

                        $purchased_qty = 0;
                        if(count($lims_product_purchase_data)) {
                            foreach ($lims_product_purchase_data as $product_purchase) {
                                $unit = DB::table('units')->find($product_purchase->purchase_unit_id);
                                if($unit->operator == '*'){
                                    $purchased_qty += $product_purchase->qty * $unit->operation_value;
                                }
                                elseif($unit->operator == '/'){
                                    $purchased_qty += $product_purchase->qty / $unit->operation_value;
                                }
                            }
                        }
                        $nestedData['purchased_qty'] = $purchased_qty;


                        $product_variant_data = ProductVariant::where([
                            ['product_id', $product->id],
                            ['variant_id', $variant_id]
                        ])->select('qty')->first();
                        $nestedData['in_stock'] = $product_variant_data->qty;

                        $data[] = $nestedData;
                    }
                } else {
                    $nestedData['key'] = count($data);
                    $imeis = $this->findImeis($product->id);
                    $nestedData['name'] = $product->name.'<br>'. 'Product Code: ' . $product->code . ($imeis != 'N/A' ? '<br>' . 'IMEI: ' . str_replace("<br/>", ",", $imeis) : '');
                    $nestedData['category'] = $product->category->name;
                    //purchase data
                    $nestedData['purchased_amount'] = DB::table('purchases')
                                                        ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')->where([
                                                            ['product_purchases.product_id', $product->id],
                                                        ])->whereNull('purchases.deleted_at')
                                                        ->whereDate('purchases.created_at','>=', $start_date)->whereDate('purchases.created_at','<=', $end_date)->sum(DB::raw('product_purchases.total / purchases.exchange_rate'));
                                                    
                    $lims_product_purchase_data = ProductPurchase::select('purchase_unit_id', 'qty')->where('product_id', $product->id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->get();

                    $purchased_qty = 0;
                    if(count($lims_product_purchase_data)) {
                        foreach ($lims_product_purchase_data as $product_purchase) {
                            $unit = DB::table('units')->find($product_purchase->purchase_unit_id);
                            if($unit->operator == '*'){
                                $purchased_qty += $product_purchase->qty * $unit->operation_value;
                            }
                            elseif($unit->operator == '/'){
                                $purchased_qty += $product_purchase->qty / $unit->operation_value;
                            }
                        }
                    }
                    $nestedData['purchased_qty'] = $purchased_qty;
                    $nestedData['in_stock'] = $product->qty;

                    $data[] = $nestedData;
                }
            }
            else {
                if($product->is_variant) {
                    $variant_id_all = ProductVariant::where('product_id', $product->id)->pluck('variant_id', 'item_code');

                    foreach ($variant_id_all as $item_code => $variant_id) {
                        $variant_data = Variant::select('name')->find($variant_id);
                        $nestedData['key'] = count($data);
                        $nestedData['name'] = $product->name . ' [' . $variant_data->name . ']'.'<br>'. 'Product Code: ' . $item_code;
                        $nestedData['category'] = $product->category->name;
                        //purchase data
                        $nestedData['purchased_amount'] = DB::table('purchases')
                                    ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')->where([
                                        ['product_purchases.product_id', $product->id],
                                        ['product_purchases.variant_id', $variant_id],
                                        ['purchases.warehouse_id', $warehouse_id]
                                    ])->whereNull('purchases.deleted_at')
                                    ->whereDate('purchases.created_at','>=', $start_date)->whereDate('purchases.created_at','<=', $end_date)->sum(DB::raw('product_purchases.total / purchases.exchange_rate'));
                        $lims_product_purchase_data = DB::table('purchases')
                                    ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')->where([
                                        ['product_purchases.product_id', $product->id],
                                        ['product_purchases.variant_id', $variant_id],
                                        ['purchases.warehouse_id', $warehouse_id]
                                    ])->whereNull('purchases.deleted_at')
                                    ->whereDate('purchases.created_at','>=', $start_date)->whereDate('purchases.created_at','<=', $end_date)
                                        ->select('product_purchases.purchase_unit_id', 'product_purchases.qty')
                                        ->get();

                        $purchased_qty = 0;
                        if(count($lims_product_purchase_data)) {
                            foreach ($lims_product_purchase_data as $product_purchase) {
                                $unit = DB::table('units')->find($product_purchase->purchase_unit_id);
                                if($unit->operator == '*'){
                                    $purchased_qty += $product_purchase->qty * $unit->operation_value;
                                }
                                elseif($unit->operator == '/'){
                                    $purchased_qty += $product_purchase->qty / $unit->operation_value;
                                }
                            }
                        }
                        $nestedData['purchased_qty'] = $purchased_qty;

                        $product_warehouse = Product_Warehouse::where([
                            ['product_id', $product->id],
                            ['variant_id', $variant_id],
                            ['warehouse_id', $warehouse_id]
                        ])->select('qty')->first();
                        if($product_warehouse)
                            $nestedData['in_stock'] = $product_warehouse->qty;
                        else
                            $nestedData['in_stock'] = 0;

                        $data[] = $nestedData;
                    }
                }
                else {
                    $nestedData['key'] = count($data);
                    $nestedData['name'] = $product->name.'<br>'. 'Product Code: ' . $product->code;
                    $nestedData['category'] = $product->category->name;
                    //purchase data
                    $nestedData['purchased_amount'] = DB::table('purchases')
                                ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')->where([
                                    ['product_purchases.product_id', $product->id],
                                    ['purchases.warehouse_id', $warehouse_id]
                                ])->whereNull('purchases.deleted_at')
                                ->whereDate('purchases.created_at','>=', $start_date)->whereDate('purchases.created_at','<=', $end_date)->sum(DB::raw('product_purchases.total / purchases.exchange_rate'));
                    $lims_product_purchase_data = DB::table('purchases')
                                ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')->where([
                                    ['product_purchases.product_id', $product->id],
                                    ['purchases.warehouse_id', $warehouse_id]
                                ])->whereNull('purchases.deleted_at')
                                ->whereDate('purchases.created_at','>=', $start_date)->whereDate('purchases.created_at','<=', $end_date)
                                    ->select('product_purchases.purchase_unit_id', 'product_purchases.qty')
                                    ->get();

                    $purchased_qty = 0;
                    if(count($lims_product_purchase_data)) {
                        foreach ($lims_product_purchase_data as $product_purchase) {
                            $unit = DB::table('units')->find($product_purchase->purchase_unit_id);
                            if($unit->operator == '*'){
                                $purchased_qty += $product_purchase->qty * $unit->operation_value;
                            }
                            elseif($unit->operator == '/'){
                                $purchased_qty += $product_purchase->qty / $unit->operation_value;
                            }
                        }
                    }
                    $nestedData['purchased_qty'] = $purchased_qty;

                    $product_warehouse = Product_Warehouse::where([
                        ['product_id', $product->id],
                        ['warehouse_id', $warehouse_id]
                    ])->select('qty')->first();
                    if($product_warehouse)
                        $nestedData['in_stock'] = $product_warehouse->qty;
                    else
                        $nestedData['in_stock'] = 0;

                    $data[] = $nestedData;
                }
            }
        }

        /*$totalData = count($data);
        $totalFiltered = $totalData;*/
        $json_data = array(
            "draw"            => intval($request->input('draw')),
            "recordsTotal"    => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data"            => $data
        );

        echo json_encode($json_data);
    }

    public function saleReport(Request $request)
    {
        $this->reportDefaults($request, ['warehouse_id' => 0]);
        $data = $request->all();
        $start_date = $data['start_date'];
        $end_date = $data['end_date'];
        $warehouse_id = $data['warehouse_id'];
        $category_id = $data['category_id'] ?? 0;
        $lims_warehouse_list = Warehouse::where('is_active', true)->get();
        // Fetch custom fields data
        $custom_fields = CustomField::where([
            ['belongs_to', 'sale'],
            ['is_table', true]
        ])->pluck('name');
        $field_names = [];
        foreach($custom_fields as $fieldName) {
            $field_names[] = str_replace(" ", "_", strtolower($fieldName));
        }

        return view('backend.report.sale_report',compact('start_date', 'end_date', 'warehouse_id', 'category_id', 'lims_warehouse_list', 'custom_fields', 'field_names'));
    }

    public function saleReportData(Request $request)
    {
        // -----------------------------
        // Basic filters
        // -----------------------------
        $start_date   = $request->start_date . ' 00:00:00';
        $end_date     = $request->end_date . ' 23:59:59';
        $warehouse_id = (int) $request->warehouse_id;
    
        // -----------------------------
        // DataTables params
        // -----------------------------
        $columns = [1 => 'name'];
        $limit   = $request->length == -1 ? 100000 : $request->length;
        $start   = $request->start ?? 0;
    
        $orderColumnIndex = $request->input('order.0.column', 1);
        $order            = $columns[$orderColumnIndex] ?? 'name';
        $dir              = $request->input('order.0.dir', 'asc');
        $search           = $request->input('search.value');
    
        // -----------------------------
        // Custom fields
        // -----------------------------
        $custom_fields = CustomField::where([
            ['belongs_to', 'sale'],
            ['is_table', true]
        ])->pluck('type', 'name');
    
        // -----------------------------
        // Preload helpers
        // -----------------------------
        $units    = DB::table('units')->get()->keyBy('id');
        $variants = Variant::pluck('name', 'id');
    
        // -----------------------------
        // VALID SALES (DRIVING DATASET)
        // -----------------------------
        $validSaleIds = Sale::whereNull('deleted_at')
            ->whereBetween('created_at', [$start_date, $end_date])
            ->when($warehouse_id > 0, fn ($q) => $q->where('warehouse_id', $warehouse_id))
            ->pluck('id');
    
        if ($validSaleIds->isEmpty()) {
            return response()->json([
                'draw' => intval($request->draw),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => []
            ]);
        }
    
        // -----------------------------
        // SOLD PRODUCT IDS (FROM SALES)
        // -----------------------------
        $soldProductIds = Product_Sale::whereIn('sale_id', $validSaleIds)
            ->pluck('product_id')
            ->unique();
    
        // -----------------------------
        // Base product query
        // -----------------------------
        $productQuery = Product::with('category')
            ->where('is_active', true)
            ->whereIn('id', $soldProductIds);
    
        if ($search) {
            $productQuery->where('name', 'LIKE', "%{$search}%");
        }
    
        $totalData = $productQuery->count();
    
        $products = $productQuery
            ->select('id', 'name', 'code', 'category_id', 'qty', 'is_variant')
            ->orderBy($order, $dir)
            ->offset($start)
            ->limit($limit)
            ->get();
    
        // -----------------------------
        // Product variants preload
        // -----------------------------
        $productVariants = ProductVariant::whereIn('product_id', $products->pluck('id'))
            ->get()
            ->groupBy('product_id');
    
        // -----------------------------
        // Warehouse stock preload
        // -----------------------------
        $warehouseStock = Product_Warehouse::whereIn('product_id', $products->pluck('id'))
            ->when($warehouse_id > 0, fn ($q) => $q->where('warehouse_id', $warehouse_id))
            ->get()
            ->groupBy(fn ($r) => $r->product_id . '_' . ($r->variant_id ?? 0));
    
        // -----------------------------
        // SALES AGGREGATION (SALES DATE SAFE)
        // -----------------------------
        $salesAgg = Product_Sale::select(
                'product_id',
                'variant_id',
                DB::raw('SUM(qty) as total_qty'),
                DB::raw('SUM(total / sales.exchange_rate) as total_amount'),
                DB::raw('GROUP_CONCAT(DISTINCT sale_id) as sale_ids')
            )
            ->join('sales', 'sales.id', '=', 'product_sales.sale_id')
            ->whereIn('product_sales.sale_id', $validSaleIds)
            ->groupBy('product_id', 'variant_id')
            ->get()
            ->keyBy(fn ($r) => $r->product_id . '_' . ($r->variant_id ?? 0));
    
        // -----------------------------
        // Build response rows
        // -----------------------------
        $data = [];
        $rowKey = 0;
        $customCache = [];
    
        foreach ($products as $product) {
    
            $variantList = $product->is_variant
                ? ($productVariants[$product->id] ?? collect())
                : collect([null]);
    
            foreach ($variantList as $variantRow) {
    
                $variant_id = $variantRow->variant_id ?? 0;
                $indexKey   = $product->id . '_' . $variant_id;
                $agg        = $salesAgg[$indexKey] ?? null;
    
                if (!$agg) continue;
    
                // Name
                $name = $product->name;
                if ($variant_id) {
                    $name .= ' [' . ($variants[$variant_id] ?? '') . ']';
                    $name .= '<br>Product Code: ' . $variantRow->item_code;
                } else {
                    $name .= '<br>Product Code: ' . $product->code;
                }
    
                // Stock
                if ($warehouse_id > 0) {
                    $stock = $warehouseStock[$indexKey][0]->qty ?? 0;
                } else {
                    $stock = $variant_id ? $variantRow->qty : $product->qty;
                }
    
                // Custom fields
                $saleIds = explode(',', $agg->sale_ids);
                $hash    = md5($agg->sale_ids);
    
                if (!isset($customCache[$hash])) {
                    $sales = Sale::whereIn('id', $saleIds)->get();
                    $customCache[$hash] = $this->reportCustomField($sales, $custom_fields);
                }
    
                // Row
                $row = [
                    'key'         => $rowKey++,
                    'name'        => $name,
                    'category'    => $product->category->name ?? '',
                    'sold_qty'    => $agg->total_qty,
                    'sold_amount' => round($agg->total_amount, 2),
                    'in_stock'    => $stock,
                ];
    
                foreach ($customCache[$hash] as $k => $v) {
                    $row[$k] = $v;
                }
    
                $data[] = $row;
            }
        }
    
        // -----------------------------
        // DataTables response
        // -----------------------------
        return response()->json([
            'draw'            => intval($request->draw),
            'recordsTotal'    => $totalData,
            'recordsFiltered' => $totalData,
            'data'            => $data
        ]);
    }

    private function reportCustomField($data, $custom_fields)
    {
        $custom_data = [];

        foreach ($custom_fields as $field_name => $type) {
            $lower_field_name = str_replace(" ", "_", strtolower($field_name));
            if ($type === 'number') {
                $custom_data[$lower_field_name] = $data->sum($lower_field_name);
            } else {
                $custom_data[$lower_field_name] = $data->pluck($lower_field_name)->filter()->values();
            }
        }
        
        return $custom_data;
    }

    public function challanReport(Request $request)
    {
        if($request->input('starting_date')) {
            $starting_date = $request->input('starting_date');
            $ending_date = $request->input('ending_date');
            $based_on = $request->input('based_on');
        }
        else {
            $starting_date = date("Y-m-"."01");
            $ending_date = date("Y-m-d");
            $based_on = 'created_at';
        }
        $challan_data = Challan::whereDate($based_on, '>=', $starting_date)->whereDate($based_on, '<=', $ending_date)->where('status', 'Close')->get();
        $index = 0;
        return view('backend.report.challan_report', compact('index', 'challan_data', 'based_on', 'starting_date', 'ending_date'));
    }

    public function saleReportChart(Request $request)
    {
        $this->reportDefaults($request, ['warehouse_id' => 0, 'time_period' => 'weekly']);
        $start_date = $request->start_date;
        $end_date = strtotime($request->end_date);
        $warehouse_id = $request->warehouse_id;
        $time_period = $request->time_period;
        if($time_period == 'monthly') {
            for($i = strtotime($start_date); $i <= $end_date; $i = strtotime('+1 month', $i)) {
                $date_points[] = date('Y-m-d', $i);
            }
        }
        else {
            for($i = strtotime('Saturday', strtotime($start_date)); $i <= $end_date; $i = strtotime('+1 week', $i)) {
                $date_points[] = date('Y-m-d', $i);
            }
        }
        $date_points[] = $request->end_date;
        //return $date_points;
        foreach ($date_points as $key => $date_point) {
            $q = DB::table('sales')
                ->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')
                ->whereNull('sales.deleted_at')
                ->whereDate('sales.created_at', '>=', $start_date)
                ->whereDate('sales.created_at', '<', $date_point);
            if($warehouse_id)
                $qty = $q->where('sales.warehouse_id', $warehouse_id);
            if(isset($request->product_list)) {
                $product_ids = Product::whereIn('code', explode(",", trim($request->product_list)))->pluck('id')->toArray();
                $q->whereIn('product_sales.product_id', $product_ids);
            }
            $qty = $q->sum('product_sales.qty');
            $sold_qty[$key] = $qty;
            $start_date = $date_point;
        }
        $lims_warehouse_list = Warehouse::where('is_active', true)->select('id', 'name')->get();
        $start_date = $request->start_date;
        $end_date = $request->end_date;
        return view('backend.report.sale_report_chart', compact('start_date', 'end_date', 'warehouse_id', 'time_period', 'sold_qty', 'date_points', 'lims_warehouse_list'));
    }

    public function paymentReportByDate(Request $request)
    {
        $this->reportDefaults($request);
        $data = $request->all();
        $start_date = $data['start_date'];
        $end_date = $data['end_date'];

        $lims_payment_data = Payment::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->get();
        return view('backend.report.payment_report',compact('lims_payment_data', 'start_date', 'end_date'));
    }

    public function warehouseReport(Request $request)
    {
        $warehouse_id = $request->input('warehouse_id');

        if($request->input('start_date')) {
            $start_date = $request->input('start_date');
            $end_date = $request->input('end_date');
        }
        else {
            $start_date = date("Y-m-d", strtotime(date('Y-m-d', strtotime('-1 year', strtotime(date('Y-m-d') )))));
            $end_date = date("Y-m-d");
        }
        $lims_warehouse_list = Warehouse::where('is_active', true)->get();
        return view('backend.report.warehouse_report',compact('start_date', 'end_date', 'warehouse_id', 'lims_warehouse_list'));
    }

    public function warehouseSaleData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $warehouse_id = $request->input('warehouse_id');
        $q = DB::table('sales')
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->where('sales.warehouse_id', $warehouse_id)
            ->whereNull('sales.deleted_at')
            ->whereDate('sales.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('sales.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'sales.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('sales.id', 'sales.reference_no', 'sales.grand_total', 'sales.paid_amount', 'sales.sale_status', 'sales.created_at', 'customers.name as customer')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $sales = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('sales.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $sales =  $q->orwhere([
                                ['sales.reference_no', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['sales.created_at', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['sales.reference_no', 'LIKE', "%{$search}%"],
                                    ['sales.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['sales.created_at', 'LIKE', "%{$search}%"],
                                    ['sales.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $sales =  $q->orwhere('sales.created_at', 'LIKE', "%{$search}%")->orwhere('sales.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('sales.created_at', 'LIKE', "%{$search}%")->orwhere('sales.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($sales))
        {
            foreach ($sales as $key => $sale)
            {
                $nestedData['id'] = $sale->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($sale->created_at));
                $nestedData['reference_no'] = $sale->reference_no;
                $nestedData['customer'] = $sale->customer;
                $product_sale_data = DB::table('sales')->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')
                                    ->join('products', 'product_sales.product_id', '=', 'products.id')
                                    ->whereNull('sales.deleted_at')
                                    ->where('sales.id', $sale->id)
                                    ->select('products.name as product_name', 'product_sales.qty', 'product_sales.sale_unit_id')
                                    ->get();
                foreach ($product_sale_data as $index => $product_sale) {
                    if($product_sale->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_sale->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_sale->product_name.' ('.number_format($product_sale->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_sale->product_name.' ('.number_format($product_sale->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($sale->grand_total, cache()->get('general_setting')->decimal);
                $nestedData['paid'] = number_format($sale->paid_amount, cache()->get('general_setting')->decimal);
                $nestedData['due'] = number_format($sale->grand_total - $sale->paid_amount, cache()->get('general_setting')->decimal);
                if($sale->sale_status == 1){
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Completed').'</div>';
                    $sale_status = __('db.Completed');
                }
                elseif($sale->sale_status == 2){
                    $nestedData['status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                    $sale_status = __('db.Pending');
                }
                else{
                    $nestedData['status'] = '<div class="badge badge-warning">'.__('db.Draft').'</div>';
                    $sale_status = __('db.Draft');
                }
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

    public function warehousePurchaseData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $warehouse_id = $request->input('warehouse_id');
        $q = DB::table('purchases')
            //->join('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->where('purchases.warehouse_id', $warehouse_id)
            ->whereNull('deleted_at')
            ->whereDate('purchases.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('purchases.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'purchases.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('purchases.id', 'purchases.reference_no', 'purchases.supplier_id', 'purchases.grand_total', 'purchases.paid_amount', 'purchases.status', 'purchases.created_at')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $purchases = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('purchases.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $purchases =  $q->orwhere([
                                ['purchases.reference_no', 'LIKE', "%{$search}%"],
                                ['purchases.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['purchases.created_at', 'LIKE', "%{$search}%"],
                                ['purchases.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['purchases.reference_no', 'LIKE', "%{$search}%"],
                                    ['purchases.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['purchases.created_at', 'LIKE', "%{$search}%"],
                                    ['purchases.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $purchases =  $q->orwhere('purchases.created_at', 'LIKE', "%{$search}%")->orwhere('purchases.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('purchases.created_at', 'LIKE', "%{$search}%")->orwhere('purchases.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($purchases))
        {
            foreach ($purchases as $key => $purchase)
            {
                $nestedData['id'] = $purchase->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($purchase->created_at));
                $nestedData['reference_no'] = $purchase->reference_no;
                if($purchase->supplier_id) {
                    $supplier = DB::table('suppliers')->select('name')->where('id',$purchase->supplier_id)->first();
                    $nestedData['supplier'] = $supplier->name;
                }
                else
                    $nestedData['supplier'] = 'N/A';
                $product_purchase_data = DB::table('purchases')->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')
                                    ->join('products', 'product_purchases.product_id', '=', 'products.id')
                                    ->where('purchases.id', $purchase->id)
                                    ->whereNull('purchases.deleted_at')
                                    ->select('products.name as product_name', 'product_purchases.qty', 'product_purchases.purchase_unit_id')
                                    ->get();
                foreach ($product_purchase_data as $index => $product_purchase) {
                    if($product_purchase->purchase_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_purchase->purchase_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_purchase->product_name.' ('.number_format($product_purchase->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_purchase->product_name.' ('.number_format($product_purchase->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($purchase->grand_total, cache()->get('general_setting')->decimal);
                $nestedData['paid'] = number_format($purchase->paid_amount, cache()->get('general_setting')->decimal);
                $nestedData['balance'] = number_format($purchase->grand_total - $purchase->paid_amount, cache()->get('general_setting')->decimal);
                if($purchase->status == 1){
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Completed').'</div>';
                    $status = __('db.Completed');
                }
                elseif($purchase->status == 2){
                    $nestedData['status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                    $status = __('db.Pending');
                }
                else{
                    $nestedData['status'] = '<div class="badge badge-warning">'.__('db.Draft').'</div>';
                    $status = __('db.Draft');
                }
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

    public function warehouseQuotationData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $warehouse_id = $request->input('warehouse_id');
        $q = DB::table('quotations')
            ->join('customers', 'quotations.customer_id', '=', 'customers.id')
            ->leftJoin('suppliers', 'quotations.supplier_id', '=', 'suppliers.id')
            ->join('warehouses', 'quotations.warehouse_id', '=', 'warehouses.id')
            ->where('quotations.warehouse_id', $warehouse_id)
            ->whereDate('quotations.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('quotations.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'quotations.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('quotations.id', 'quotations.reference_no', 'quotations.supplier_id', 'quotations.grand_total', 'quotations.quotation_status', 'quotations.created_at', 'suppliers.name as supplier_name', 'customers.name as customer_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $quotations = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('quotations.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $quotations =  $q->orwhere([
                                ['quotations.reference_no', 'LIKE', "%{$search}%"],
                                ['quotations.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['quotations.created_at', 'LIKE', "%{$search}%"],
                                ['quotations.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['quotations.reference_no', 'LIKE', "%{$search}%"],
                                    ['quotations.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['quotations.created_at', 'LIKE', "%{$search}%"],
                                    ['quotations.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $quotations =  $q->orwhere('quotations.created_at', 'LIKE', "%{$search}%")->orwhere('quotations.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('quotations.created_at', 'LIKE', "%{$search}%")->orwhere('quotations.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($quotations))
        {
            foreach ($quotations as $key => $quotation)
            {
                $nestedData['id'] = $quotation->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($quotation->created_at));
                $nestedData['reference_no'] = $quotation->reference_no;
                $nestedData['customer'] = $quotation->customer_name;
                if($quotation->supplier_id) {
                    $nestedData['supplier'] = $quotation->supplier_name;
                }
                else
                    $nestedData['supplier'] = 'N/A';
                $product_quotation_data = DB::table('quotations')->join('product_quotation', 'quotations.id', '=', 'product_quotation.quotation_id')
                                    ->join('products', 'product_quotation.product_id', '=', 'products.id')
                                    ->where('quotations.id', $quotation->id)
                                    ->select('products.name as product_name', 'product_quotation.qty', 'product_quotation.sale_unit_id')
                                    ->get();
                foreach ($product_quotation_data as $index => $product_return) {
                    if($product_return->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_return->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($quotation->grand_total, cache()->get('general_setting')->decimal);
                if($quotation->quotation_status == 1){
                    $nestedData['status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                }
                else{
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Sent').'</div>';
                }
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

    public function warehouseReturnData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $warehouse_id = $request->input('warehouse_id');
        $q = DB::table('returns')
            ->join('customers', 'returns.customer_id', '=', 'customers.id')
            ->leftJoin('billers', 'returns.biller_id', '=', 'billers.id')
            ->where('returns.warehouse_id', $warehouse_id)
            ->whereDate('returns.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('returns.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'returns.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('returns.id', 'returns.reference_no', 'returns.grand_total', 'returns.created_at', 'customers.name as customer_name', 'billers.name as biller_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $returns = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('returns.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $returns =  $q->orwhere([
                                ['returns.reference_no', 'LIKE', "%{$search}%"],
                                ['returns.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['returns.created_at', 'LIKE', "%{$search}%"],
                                ['returns.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['returns.reference_no', 'LIKE', "%{$search}%"],
                                    ['returns.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['returns.created_at', 'LIKE', "%{$search}%"],
                                    ['returns.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $returns =  $q->orwhere('returns.created_at', 'LIKE', "%{$search}%")->orwhere('returns.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('returns.created_at', 'LIKE', "%{$search}%")->orwhere('returns.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($returns))
        {
            foreach ($returns as $key => $sale)
            {
                $nestedData['id'] = $sale->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($sale->created_at));
                $nestedData['reference_no'] = $sale->reference_no;
                $nestedData['customer'] = $sale->customer_name;
                $nestedData['biller'] = $sale->biller_name;
                $product_return_data = DB::table('returns')->join('product_returns', 'returns.id', '=', 'product_returns.return_id')
                                    ->join('products', 'product_returns.product_id', '=', 'products.id')
                                    ->where('returns.id', $sale->id)
                                    ->select('products.name as product_name', 'product_returns.qty', 'product_returns.sale_unit_id')
                                    ->get();
                foreach ($product_return_data as $index => $product_return) {
                    if($product_return->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_return->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($sale->grand_total, cache()->get('general_setting')->decimal);
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

    public function warehouseExpenseData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $warehouse_id = $request->input('warehouse_id');
        $q = DB::table('expenses')
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->where('expenses.warehouse_id', $warehouse_id)
            ->whereDate('expenses.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('expenses.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'expenses.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('expenses.id', 'expenses.reference_no', 'expenses.amount', 'expenses.created_at', 'expenses.note', 'expense_categories.name as category')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $expenses = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('expenses.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $expenses =  $q->orwhere([
                                ['expenses.reference_no', 'LIKE', "%{$search}%"],
                                ['expenses.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['expenses.created_at', 'LIKE', "%{$search}%"],
                                ['expenses.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['expenses.reference_no', 'LIKE', "%{$search}%"],
                                    ['expenses.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['expenses.created_at', 'LIKE', "%{$search}%"],
                                    ['expenses.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $expenses =  $q->orwhere('expenses.created_at', 'LIKE', "%{$search}%")->orwhere('expenses.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('expenses.created_at', 'LIKE', "%{$search}%")->orwhere('expenses.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($expenses))
        {
            foreach ($expenses as $key => $expense)
            {
                $nestedData['id'] = $expense->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($expense->created_at));
                $nestedData['reference_no'] = $expense->reference_no;
                $nestedData['category'] = $expense->category;
                $nestedData['amount'] = number_format($expense->amount, cache()->get('general_setting')->decimal);
                $nestedData['note'] = $expense->note;
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

    public function userReport(Request $request)
    {
        $this->reportDefaults($request, ['user_id' => Auth::id()]);
        $data = $request->all();
        $user_id = $data['user_id'];
        $start_date = $data['start_date'];
        $end_date = $data['end_date'];
        $lims_user_list = User::where('is_active', true)->get();
        return view('backend.report.user_report', compact('user_id', 'start_date', 'end_date', 'lims_user_list'));
    }

    public function billerReport(Request $request)
    {
        $this->reportDefaults($request, ['biller_id' => 0]);
        $data = $request->all();
        $biller_id = $data['biller_id'];
        $start_date = $data['start_date'];
        $end_date = $data['end_date'];
        $lims_biller_list = Biller::where('is_active', true)->get();
        return view('backend.report.biller_report', compact('biller_id', 'start_date', 'end_date', 'lims_biller_list'));
    }

    public function billerSaleData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $biller_id = $request->input('biller_id');

        $q = DB::table('sales')
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->join('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->whereNull('sales.deleted_at')
            ->where('sales.biller_id', $biller_id)
            ->whereDate('sales.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('sales.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'sales.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('sales.id', 'sales.reference_no', 'sales.grand_total', 'sales.paid_amount', 'sales.sale_status', 'sales.created_at', 'customers.name as customer', 'warehouses.name as warehouse')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $sales = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('sales.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $sales =  $q->orwhere([
                                ['sales.reference_no', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['sales.created_at', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['sales.reference_no', 'LIKE', "%{$search}%"],
                                    ['sales.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['sales.created_at', 'LIKE', "%{$search}%"],
                                    ['sales.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $sales =  $q->orwhere('sales.created_at', 'LIKE', "%{$search}%")->orwhere('sales.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('sales.created_at', 'LIKE', "%{$search}%")->orwhere('sales.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($sales))
        {
            foreach ($sales as $key => $sale)
            {
                $nestedData['id'] = $sale->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($sale->created_at));
                $nestedData['reference_no'] = $sale->reference_no;
                $nestedData['customer'] = $sale->customer;
                $nestedData['warehouse'] = $sale->warehouse;
                $product_sale_data = DB::table('sales')->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')
                                    ->join('products', 'product_sales.product_id', '=', 'products.id')
                                    ->whereNull('sales.deleted_at')
                                    ->where('sales.id', $sale->id)
                                    ->select('products.name as product_name', 'product_sales.qty', 'product_sales.sale_unit_id')
                                    ->get();
                $nestedData['product'] = '';
                foreach ($product_sale_data as $index => $product_sale) {
                    if($product_sale->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_sale->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_sale->product_name.' ('.number_format($product_sale->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_sale->product_name.' ('.number_format($product_sale->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($sale->grand_total, cache()->get('general_setting')->decimal);
                $nestedData['paid'] = number_format($sale->paid_amount, cache()->get('general_setting')->decimal);
                $nestedData['due'] = number_format($sale->grand_total - $sale->paid_amount, cache()->get('general_setting')->decimal);
                if($sale->sale_status == 1){
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Completed').'</div>';
                    $sale_status = __('db.Completed');
                }
                elseif($sale->sale_status == 2){
                    $nestedData['sale_status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                    $sale_status = __('db.Pending');
                }
                else{
                    $nestedData['sale_status'] = '<div class="badge badge-warning">'.__('db.Draft').'</div>';
                    $sale_status = __('db.Draft');
                }
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

    public function billerQuotationData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $biller_id = $request->input('biller_id');
        $q = DB::table('quotations')
            ->join('customers', 'quotations.customer_id', '=', 'customers.id')
            ->join('warehouses', 'quotations.warehouse_id', '=', 'warehouses.id')
            ->where('quotations.biller_id', $biller_id)
            ->whereDate('quotations.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('quotations.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'quotations.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('quotations.id', 'quotations.reference_no', 'quotations.grand_total', 'quotations.quotation_status', 'quotations.created_at', 'warehouses.name as warehouse_name', 'customers.name as customer_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $quotations = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('quotations.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $quotations =  $q->orwhere([
                                ['quotations.reference_no', 'LIKE', "%{$search}%"],
                                ['quotations.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['quotations.created_at', 'LIKE', "%{$search}%"],
                                ['quotations.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['quotations.reference_no', 'LIKE', "%{$search}%"],
                                    ['quotations.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['quotations.created_at', 'LIKE', "%{$search}%"],
                                    ['quotations.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $quotations =  $q->orwhere('quotations.created_at', 'LIKE', "%{$search}%")->orwhere('quotations.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('quotations.created_at', 'LIKE', "%{$search}%")->orwhere('quotations.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($quotations))
        {
            foreach ($quotations as $key => $quotation)
            {
                $nestedData['id'] = $quotation->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($quotation->created_at));
                $nestedData['reference_no'] = $quotation->reference_no;
                $nestedData['customer'] = $quotation->customer_name;
                $nestedData['warehouse'] = $quotation->warehouse_name;
                $product_quotation_data = DB::table('quotations')->join('product_quotation', 'quotations.id', '=', 'product_quotation.quotation_id')
                                    ->join('products', 'product_quotation.product_id', '=', 'products.id')
                                    ->where('quotations.id', $quotation->id)
                                    ->select('products.name as product_name', 'product_quotation.qty', 'product_quotation.sale_unit_id')
                                    ->get();
                foreach ($product_quotation_data as $index => $product_return) {
                    if($product_return->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_return->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($quotation->grand_total, cache()->get('general_setting')->decimal);
                if($quotation->quotation_status == 1){
                    $nestedData['status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                }
                else{
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Sent').'</div>';
                }
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

    public function billerPaymentData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $biller_id = $request->input('biller_id');
        $q = DB::table('payments')
           ->join('sales', 'payments.sale_id', '=', 'sales.id')
           ->whereNull('sales.deleted_at')
           ->where('sales.biller_Id',$biller_id)
           ->whereDate('payments.created_at', '>=' , $request->input('start_date'))
           ->whereDate('payments.created_at', '<=' , $request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'payments.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('payments.*')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $payments = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('payments.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $payments =  $q->orwhere([
                                ['payments.payment_reference', 'LIKE', "%{$search}%"],
                                ['payments.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['payments.created_at', 'LIKE', "%{$search}%"],
                                ['payments.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['payments.payment_reference', 'LIKE', "%{$search}%"],
                                    ['payments.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['payments.created_at', 'LIKE', "%{$search}%"],
                                    ['payments.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $payments =  $q->orwhere('payments.created_at', 'LIKE', "%{$search}%")->orwhere('payments.payment_reference', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('payments.created_at', 'LIKE', "%{$search}%")->orwhere('payments.payment_reference', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($payments))
        {
            foreach ($payments as $key => $payment)
            {
                $nestedData['id'] = $payment->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($payment->created_at));
                $nestedData['reference_no'] = $payment->payment_reference;
                $nestedData['amount'] = number_format($payment->amount, cache()->get('general_setting')->decimal);
                $nestedData['paying_method'] = $payment->paying_method;
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

    public function userSaleData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $user_id = $request->input('user_id');
        $q = DB::table('sales')
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->join('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->whereNull('deleted_at')
            ->where('sales.user_id', $user_id)
            ->whereDate('sales.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('sales.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'sales.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('sales.id', 'sales.reference_no', 'sales.grand_total', 'sales.paid_amount', 'sales.sale_status', 'sales.created_at', 'customers.name as customer', 'warehouses.name as warehouse')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $sales = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('sales.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $sales =  $q->orwhere([
                                ['sales.reference_no', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['sales.created_at', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['sales.reference_no', 'LIKE', "%{$search}%"],
                                    ['sales.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['sales.created_at', 'LIKE', "%{$search}%"],
                                    ['sales.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $sales =  $q->orwhere('sales.created_at', 'LIKE', "%{$search}%")->orwhere('sales.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('sales.created_at', 'LIKE', "%{$search}%")->orwhere('sales.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($sales))
        {
            foreach ($sales as $key => $sale)
            {
                $nestedData['id'] = $sale->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($sale->created_at));
                $nestedData['reference_no'] = $sale->reference_no;
                $nestedData['customer'] = $sale->customer;
                $nestedData['warehouse'] = $sale->warehouse;
                $product_sale_data = DB::table('sales')->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')
                                    ->join('products', 'product_sales.product_id', '=', 'products.id')
                                    ->where('sales.id', $sale->id)
                                    ->whereNull('sales.deleted_at')
                                    ->select('products.name as product_name', 'product_sales.qty', 'product_sales.sale_unit_id')
                                    ->get();
                foreach ($product_sale_data as $index => $product_sale) {
                    if($product_sale->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_sale->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_sale->product_name.' ('.number_format($product_sale->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_sale->product_name.' ('.number_format($product_sale->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($sale->grand_total, cache()->get('general_setting')->decimal);
                $nestedData['paid'] = number_format($sale->paid_amount, cache()->get('general_setting')->decimal);
                $nestedData['due'] = number_format($sale->grand_total - $sale->paid_amount, cache()->get('general_setting')->decimal);
                if($sale->sale_status == 1){
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Completed').'</div>';
                    $sale_status = __('db.Completed');
                }
                elseif($sale->sale_status == 2){
                    $nestedData['sale_status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                    $sale_status = __('db.Pending');
                }
                else{
                    $nestedData['sale_status'] = '<div class="badge badge-warning">'.__('db.Draft').'</div>';
                    $sale_status = __('db.Draft');
                }
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

    public function userPurchaseData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $user_id = $request->input('user_id');
        $q = DB::table('purchases')
            ->join('warehouses', 'purchases.warehouse_id', '=', 'warehouses.id')
            ->where('purchases.user_id', $user_id)
            ->whereNull('deleted_at')
            ->whereDate('purchases.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('purchases.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'purchases.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('purchases.id', 'purchases.reference_no', 'purchases.supplier_id', 'purchases.grand_total', 'purchases.paid_amount', 'purchases.status', 'purchases.created_at', 'warehouses.name as warehouse')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $purchases = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('purchases.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $purchases =  $q->orwhere([
                                ['purchases.reference_no', 'LIKE', "%{$search}%"],
                                ['purchases.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['purchases.created_at', 'LIKE', "%{$search}%"],
                                ['purchases.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['purchases.reference_no', 'LIKE', "%{$search}%"],
                                    ['purchases.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['purchases.created_at', 'LIKE', "%{$search}%"],
                                    ['purchases.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $purchases =  $q->orwhere('purchases.created_at', 'LIKE', "%{$search}%")->orwhere('purchases.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('purchases.created_at', 'LIKE', "%{$search}%")->orwhere('purchases.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($purchases))
        {
            foreach ($purchases as $key => $purchase)
            {
                $nestedData['id'] = $purchase->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($purchase->created_at));
                $nestedData['reference_no'] = $purchase->reference_no;
                $nestedData['warehouse'] = $purchase->warehouse;
                if($purchase->supplier_id) {
                    $supplier = DB::table('suppliers')->select('name')->where('id',$purchase->supplier_id)->first();
                    $nestedData['supplier'] = $supplier->name;
                }
                else
                    $nestedData['supplier'] = 'N/A';
                $product_purchase_data = DB::table('purchases')->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')
                                    ->join('products', 'product_purchases.product_id', '=', 'products.id')
                                    ->where('purchases.id', $purchase->id)
                                    ->whereNull('purchases.deleted_at')
                                    ->select('products.name as product_name', 'product_purchases.qty', 'product_purchases.purchase_unit_id')
                                    ->get();
                foreach ($product_purchase_data as $index => $product_purchase) {
                    if($product_purchase->purchase_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_purchase->purchase_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_purchase->product_name.' ('.number_format($product_purchase->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_purchase->product_name.' ('.number_format($product_purchase->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($purchase->grand_total, cache()->get('general_setting')->decimal);
                $nestedData['paid'] = number_format($purchase->paid_amount, cache()->get('general_setting')->decimal);
                $nestedData['balance'] = number_format($purchase->grand_total - $purchase->paid_amount, cache()->get('general_setting')->decimal);
                if($purchase->status == 1){
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Completed').'</div>';
                    $status = __('db.Completed');
                }
                elseif($purchase->status == 2){
                    $nestedData['status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                    $status = __('db.Pending');
                }
                else{
                    $nestedData['status'] = '<div class="badge badge-warning">'.__('db.Draft').'</div>';
                    $status = __('db.Draft');
                }
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

    public function userQuotationData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $user_id = $request->input('user_id');
        $q = DB::table('quotations')
            ->join('customers', 'quotations.customer_id', '=', 'customers.id')
            ->join('warehouses', 'quotations.warehouse_id', '=', 'warehouses.id')
            ->where('quotations.user_id', $user_id)
            ->whereDate('quotations.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('quotations.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'quotations.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('quotations.id', 'quotations.reference_no', 'quotations.grand_total', 'quotations.quotation_status', 'quotations.created_at', 'warehouses.name as warehouse_name', 'customers.name as customer_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $quotations = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('quotations.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $quotations =  $q->orwhere([
                                ['quotations.reference_no', 'LIKE', "%{$search}%"],
                                ['quotations.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['quotations.created_at', 'LIKE', "%{$search}%"],
                                ['quotations.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['quotations.reference_no', 'LIKE', "%{$search}%"],
                                    ['quotations.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['quotations.created_at', 'LIKE', "%{$search}%"],
                                    ['quotations.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $quotations =  $q->orwhere('quotations.created_at', 'LIKE', "%{$search}%")->orwhere('quotations.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('quotations.created_at', 'LIKE', "%{$search}%")->orwhere('quotations.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($quotations))
        {
            foreach ($quotations as $key => $quotation)
            {
                $nestedData['id'] = $quotation->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($quotation->created_at));
                $nestedData['reference_no'] = $quotation->reference_no;
                $nestedData['customer'] = $quotation->customer_name;
                $nestedData['warehouse'] = $quotation->warehouse_name;
                $product_quotation_data = DB::table('quotations')->join('product_quotation', 'quotations.id', '=', 'product_quotation.quotation_id')
                                    ->join('products', 'product_quotation.product_id', '=', 'products.id')
                                    ->where('quotations.id', $quotation->id)
                                    ->select('products.name as product_name', 'product_quotation.qty', 'product_quotation.sale_unit_id')
                                    ->get();
                foreach ($product_quotation_data as $index => $product_return) {
                    if($product_return->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_return->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($quotation->grand_total, cache()->get('general_setting')->decimal);
                if($quotation->quotation_status == 1){
                    $nestedData['status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                }
                else{
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Sent').'</div>';
                }
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

    public function userTransferData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $user_id = $request->input('user_id');
        $q = DB::table('transfers')
           ->join('warehouses as fromWarehouse', 'transfers.from_warehouse_id', '=', 'fromWarehouse.id')
           ->join('warehouses as toWarehouse', 'transfers.to_warehouse_id', '=', 'toWarehouse.id')
           ->where('transfers.user_id', $user_id)
           ->whereDate('transfers.created_at', '>=' , $request->input('start_date'))
           ->whereDate('transfers.created_at', '<=' , $request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'transfers.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('transfers.id', 'transfers.status', 'transfers.created_at', 'transfers.reference_no', 'transfers.grand_total', 'fromWarehouse.name as fromWarehouse', 'toWarehouse.name as toWarehouse')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $transfers = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('transfers.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $transfers =  $q->orwhere([
                                ['transfers.reference_no', 'LIKE', "%{$search}%"],
                                ['transfers.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['transfers.created_at', 'LIKE', "%{$search}%"],
                                ['transfers.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['transfers.reference_no', 'LIKE', "%{$search}%"],
                                    ['transfers.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['transfers.created_at', 'LIKE', "%{$search}%"],
                                    ['transfers.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $transfers =  $q->orwhere('transfers.created_at', 'LIKE', "%{$search}%")->orwhere('transfers.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('transfers.created_at', 'LIKE', "%{$search}%")->orwhere('transfers.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($transfers))
        {
            foreach ($transfers as $key => $transfer)
            {
                $nestedData['id'] = $transfer->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($transfer->created_at));
                $nestedData['reference_no'] = $transfer->reference_no;
                $nestedData['fromWarehouse'] = $transfer->fromWarehouse;
                $nestedData['toWarehouse'] = $transfer->toWarehouse;
                $product_transfer_data = DB::table('product_transfer')
                                    ->where('transfer_id', $transfer->id)
                                    ->get();
                foreach ($product_transfer_data as $index => $product_transfer) {
                    $product = DB::table('products')->find($product_transfer->product_id);
                    if($product_transfer->variant_id) {
                        $variant = DB::table('variants')->find($product_transfer->variant_id);
                        $product->name .= ' ['.$variant->name.']';
                    }
                    $unit = DB::table('units')->find($product_transfer->purchase_unit_id);
                    if($index){
                        if($unit){
                            $nestedData['product'] .= $product->name.' ('.$product_transfer->qty.' '.$unit->unit_code.')';
                        }else{
                            $nestedData['product'] .= $product->name.' ('.$product_transfer->qty.')';
                        }
                    }else{
                        if($unit){
                            $nestedData['product'] = $product->name.' ('.$product_transfer->qty.' '.$unit->unit_code.')';
                        }else{
                            $nestedData['product'] = $product->name.' ('.$product_transfer->qty.')';
                        }
                    }
                }
                $nestedData['grandTotal'] = number_format($transfer->grand_total, cache()->get('general_setting')->decimal);
                if($transfer->status == 1){
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Completed').'</div>';
                }
                elseif($transfer->status == 2) {
                    $nestedData['status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                }
                else{
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Sent').'</div>';
                }
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
    public function userPaymentData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $user_id = $request->input('user_id');
        $q = DB::table('payments')
           ->where('payments.user_id', $user_id)
           ->whereDate('payments.created_at', '>=' , $request->input('start_date'))
           ->whereDate('payments.created_at', '<=' , $request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'payments.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('payments.*')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $payments = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('payments.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $payments =  $q->orwhere([
                                ['payments.payment_reference', 'LIKE', "%{$search}%"],
                                ['payments.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['payments.created_at', 'LIKE', "%{$search}%"],
                                ['payments.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['payments.payment_reference', 'LIKE', "%{$search}%"],
                                    ['payments.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['payments.created_at', 'LIKE', "%{$search}%"],
                                    ['payments.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $payments =  $q->orwhere('payments.created_at', 'LIKE', "%{$search}%")->orwhere('payments.payment_reference', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('payments.created_at', 'LIKE', "%{$search}%")->orwhere('payments.payment_reference', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($payments))
        {
            foreach ($payments as $key => $payment)
            {
                $nestedData['id'] = $payment->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($payment->created_at));
                $nestedData['reference_no'] = $payment->payment_reference;
                $nestedData['amount'] = number_format($payment->amount, cache()->get('general_setting')->decimal);
                $nestedData['paying_method'] = $payment->paying_method;
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

    public function userPayrollData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $user_id = $request->input('user_id');
        $q = DB::table('payrolls')
           ->join('employees', 'payrolls.employee_id', '=', 'employees.id')
           ->where('payrolls.user_id', $user_id)
           ->whereDate('payrolls.created_at', '>=' , $request->input('start_date'))
           ->whereDate('payrolls.created_at', '<=' , $request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'payrolls.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('payrolls.id', 'payrolls.created_at', 'payrolls.reference_no', 'payrolls.amount', 'payrolls.paying_method', 'employees.name as employee')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $payrolls = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('payrolls.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $payrolls =  $q->orwhere([
                                ['payrolls.reference_no', 'LIKE', "%{$search}%"],
                                ['payrolls.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['payrolls.created_at', 'LIKE', "%{$search}%"],
                                ['payrolls.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['payrolls.reference_no', 'LIKE', "%{$search}%"],
                                    ['payrolls.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['payrolls.created_at', 'LIKE', "%{$search}%"],
                                    ['payrolls.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $payrolls =  $q->orwhere('payrolls.created_at', 'LIKE', "%{$search}%")->orwhere('payrolls.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('payrolls.created_at', 'LIKE', "%{$search}%")->orwhere('payrolls.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($payrolls))
        {
            foreach ($payrolls as $key => $payroll)
            {
                $nestedData['id'] = $payroll->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($payroll->created_at));
                $nestedData['reference_no'] = $payroll->reference_no;
                $nestedData['employee'] = $payroll->employee;
                $nestedData['amount'] = number_format($payroll->amount, cache()->get('general_setting')->decimal);
                if($payroll->paying_method == 0)
                    $nestedData['method'] = 'Cash';
                elseif($payroll->paying_method == 1)
                    $nestedData['method'] = 'Cheque';
                else
                    $nestedData['method'] = 'Credit Card';
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

    public function userExpenseData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $user_id = $request->input('user_id');
        $q = DB::table('expenses')
            ->join('warehouses', 'expenses.warehouse_id', '=', 'warehouses.id')
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->where('expenses.user_id', $user_id)
            ->whereDate('expenses.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('expenses.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'expenses.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('expenses.id', 'expenses.reference_no', 'expenses.amount', 'expenses.created_at', 'expenses.note', 'expense_categories.name as category', 'warehouses.name as warehouse')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $expenses = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('expenses.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $expenses =  $q->orwhere([
                                ['expenses.reference_no', 'LIKE', "%{$search}%"],
                                ['expenses.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['expenses.created_at', 'LIKE', "%{$search}%"],
                                ['expenses.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['expenses.reference_no', 'LIKE', "%{$search}%"],
                                    ['expenses.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['expenses.created_at', 'LIKE', "%{$search}%"],
                                    ['expenses.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $expenses =  $q->orwhere('expenses.created_at', 'LIKE', "%{$search}%")->orwhere('expenses.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('expenses.created_at', 'LIKE', "%{$search}%")->orwhere('expenses.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($expenses))
        {
            foreach ($expenses as $key => $expense)
            {
                $nestedData['id'] = $expense->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($expense->created_at));
                $nestedData['reference_no'] = $expense->reference_no;
                $nestedData['warehouse'] = $expense->warehouse;
                $nestedData['category'] = $expense->category;
                $nestedData['amount'] = number_format($expense->amount, cache()->get('general_setting')->decimal);
                $nestedData['note'] = $expense->note;
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

    public function customerReport(Request $request)
    {
        $customer_id = $request->input('customer_id');
        if($request->input('start_date')) {
            $start_date = $request->input('start_date');
            $end_date = $request->input('end_date');
        }
        else {
            $start_date = date("Y-m-d", strtotime(date('Y-m-d', strtotime('-1 year', strtotime(date('Y-m-d') )))));
            $end_date = date("Y-m-d");
        }
        $lims_customer_list = Customer::where('is_active', true)->get();
        return view('backend.report.customer_report',compact('start_date', 'end_date', 'customer_id', 'lims_customer_list'));
    }

    public function customerSaleData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $customer_id = $request->input('customer_id');
        $q = DB::table('sales')
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->join('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->where('sales.customer_id', $customer_id)
            ->whereNull('sales.deleted_at')
            ->whereDate('sales.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('sales.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'sales.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('sales.id', 'sales.reference_no', 'sales.total_price', 'sales.grand_total', 'sales.paid_amount', 'sales.sale_status', 'sales.created_at', 'warehouses.name as warehouse_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $sales = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('sales.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $sales =  $q->orwhere([
                                ['sales.reference_no', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['sales.created_at', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['sales.reference_no', 'LIKE', "%{$search}%"],
                                    ['sales.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['sales.created_at', 'LIKE', "%{$search}%"],
                                    ['sales.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $sales =  $q->orwhere('sales.created_at', 'LIKE', "%{$search}%")->orwhere('sales.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('sales.created_at', 'LIKE', "%{$search}%")->orwhere('sales.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($sales))
        {
            foreach ($sales as $key => $sale)
            {
                $nestedData['id'] = $sale->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($sale->created_at));
                $nestedData['reference_no'] = $sale->reference_no;
                $nestedData['warehouse'] = $sale->warehouse_name;
                $product_sale_data = DB::table('sales')->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')
                                    ->join('products', 'product_sales.product_id', '=', 'products.id')
                                    ->where('sales.id', $sale->id)
                                    ->whereNull('sales.deleted_at')
                                    ->select('products.name as product_name', 'product_sales.qty', 'product_sales.sale_unit_id')
                                    ->get();
                foreach ($product_sale_data as $index => $product_sale) {
                    if($product_sale->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_sale->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_sale->product_name.' ('.number_format($product_sale->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_sale->product_name.' ('.number_format($product_sale->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                //calculating product purchase cost
                config()->set('database.connections.mysql.strict', false);
                DB::reconnect();
                $product_sale_data = Sale::join('product_sales', 'sales.id','=', 'product_sales.sale_id')
                    ->select(DB::raw('product_sales.product_id, product_sales.product_batch_id, product_sales.sale_unit_id, sum(product_sales.qty) as sold_qty, sum(product_sales.total) as sold_amount'))
                    ->whereNull('sales.deleted_at')
                    ->where('sales.id', $sale->id)
                    ->whereDate('sales.created_at', '>=' , $request->input('start_date'))
                    ->whereDate('sales.created_at', '<=' , $request->input('end_date'))
                    ->groupBy('product_sales.product_id', 'product_sales.product_batch_id')
                    ->get();
                config()->set('database.connections.mysql.strict', true);
                DB::reconnect();
                $product_cost = $this->calculateAverageCOGS($product_sale_data);
                $nestedData['total_cost'] = number_format($product_cost[0], cache()->get('general_setting')->decimal);
                $nestedData['grand_total'] = number_format($sale->grand_total, cache()->get('general_setting')->decimal);
                $nestedData['paid'] = number_format($sale->paid_amount, cache()->get('general_setting')->decimal);
                $nestedData['due'] = number_format($sale->grand_total - $sale->paid_amount, cache()->get('general_setting')->decimal);
                if($sale->sale_status == 1){
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Completed').'</div>';
                    $sale_status = __('db.Completed');
                }
                elseif($sale->sale_status == 2){
                    $nestedData['sale_status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                    $sale_status = __('db.Pending');
                }
                else{
                    $nestedData['sale_status'] = '<div class="badge badge-warning">'.__('db.Draft').'</div>';
                    $sale_status = __('db.Draft');
                }
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

    public function customerPaymentData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $customer_id = $request->input('customer_id');
        $q = DB::table('payments')
           ->join('sales', 'payments.sale_id', '=', 'sales.id')
           ->join('customers', 'customers.id', '=', 'sales.customer_id')
           ->where('sales.customer_id', $customer_id)
           ->whereNull('sales.deleted_at')
           ->whereDate('payments.created_at', '>=' , $request->input('start_date'))
           ->whereDate('payments.created_at', '<=' , $request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'payments.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('payments.*', 'sales.reference_no as sale_reference')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $payments = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('payments.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $payments =  $q->orwhere([
                                ['payments.payment_reference', 'LIKE', "%{$search}%"],
                                ['payments.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['payments.created_at', 'LIKE', "%{$search}%"],
                                ['payments.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['payments.payment_reference', 'LIKE', "%{$search}%"],
                                    ['payments.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['payments.created_at', 'LIKE', "%{$search}%"],
                                    ['payments.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $payments =  $q->orwhere('payments.created_at', 'LIKE', "%{$search}%")->orwhere('payments.payment_reference', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('payments.created_at', 'LIKE', "%{$search}%")->orwhere('payments.payment_reference', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($payments))
        {
            foreach ($payments as $key => $payment)
            {
                $nestedData['id'] = $payment->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($payment->created_at));
                $nestedData['reference_no'] = $payment->payment_reference;
                $nestedData['sale_reference'] = $payment->sale_reference;
                $nestedData['amount'] = number_format($payment->amount, cache()->get('general_setting')->decimal);
                $nestedData['paying_method'] = $payment->paying_method;
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

    public function customerQuotationData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $customer_id = $request->input('customer_id');
        $q = DB::table('quotations')
            ->join('customers', 'quotations.customer_id', '=', 'customers.id')
            ->leftJoin('suppliers', 'quotations.supplier_id', '=', 'suppliers.id')
            ->join('warehouses', 'quotations.warehouse_id', '=', 'warehouses.id')
            ->where('quotations.customer_id', $customer_id)
            ->whereDate('quotations.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('quotations.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'quotations.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('quotations.id', 'quotations.reference_no', 'quotations.supplier_id', 'quotations.grand_total', 'quotations.quotation_status', 'quotations.created_at', 'suppliers.name as supplier_name', 'warehouses.name as warehouse_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $quotations = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('quotations.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $quotations =  $q->orwhere([
                                ['quotations.reference_no', 'LIKE', "%{$search}%"],
                                ['quotations.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['quotations.created_at', 'LIKE', "%{$search}%"],
                                ['quotations.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['quotations.reference_no', 'LIKE', "%{$search}%"],
                                    ['quotations.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['quotations.created_at', 'LIKE', "%{$search}%"],
                                    ['quotations.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $quotations =  $q->orwhere('quotations.created_at', 'LIKE', "%{$search}%")->orwhere('quotations.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('quotations.created_at', 'LIKE', "%{$search}%")->orwhere('quotations.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($quotations))
        {
            foreach ($quotations as $key => $quotation)
            {
                $nestedData['id'] = $quotation->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($quotation->created_at));
                $nestedData['reference_no'] = $quotation->reference_no;
                $nestedData['warehouse'] = $quotation->warehouse_name;
                if($quotation->supplier_id) {
                    $nestedData['supplier'] = $quotation->supplier_name;
                }
                else
                    $nestedData['supplier'] = 'N/A';
                $product_quotation_data = DB::table('quotations')->join('product_quotation', 'quotations.id', '=', 'product_quotation.quotation_id')
                                    ->join('products', 'product_quotation.product_id', '=', 'products.id')
                                    ->where('quotations.id', $quotation->id)
                                    ->select('products.name as product_name', 'product_quotation.qty', 'product_quotation.sale_unit_id')
                                    ->get();
                foreach ($product_quotation_data as $index => $product_return) {
                    if($product_return->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_return->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($quotation->grand_total, cache()->get('general_setting')->decimal);
                if($quotation->quotation_status == 1){
                    $nestedData['status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                }
                else{
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Sent').'</div>';
                }
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

    public function customerReturnData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $customer_id = $request->input('customer_id');
        $q = DB::table('returns')
            ->join('customers', 'returns.customer_id', '=', 'customers.id')
            ->join('warehouses', 'returns.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('billers', 'returns.biller_id', '=', 'billers.id')
            ->where('returns.customer_id', $customer_id)
            ->whereDate('returns.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('returns.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'returns.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('returns.id', 'returns.reference_no', 'returns.grand_total', 'returns.created_at', 'warehouses.name as warehouse_name', 'billers.name as biller_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $returns = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('returns.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $returns =  $q->orwhere([
                                ['returns.reference_no', 'LIKE', "%{$search}%"],
                                ['returns.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['returns.created_at', 'LIKE', "%{$search}%"],
                                ['returns.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['returns.reference_no', 'LIKE', "%{$search}%"],
                                    ['returns.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['returns.created_at', 'LIKE', "%{$search}%"],
                                    ['returns.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $returns =  $q->orwhere('returns.created_at', 'LIKE', "%{$search}%")->orwhere('returns.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('returns.created_at', 'LIKE', "%{$search}%")->orwhere('returns.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($returns))
        {
            foreach ($returns as $key => $sale)
            {
                $nestedData['id'] = $sale->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($sale->created_at));
                $nestedData['reference_no'] = $sale->reference_no;
                $nestedData['warehouse'] = $sale->warehouse_name;
                $nestedData['biller'] = $sale->biller_name;
                $product_return_data = DB::table('returns')->join('product_returns', 'returns.id', '=', 'product_returns.return_id')
                                    ->join('products', 'product_returns.product_id', '=', 'products.id')
                                    ->where('returns.id', $sale->id)
                                    ->select('products.name as product_name', 'product_returns.qty', 'product_returns.sale_unit_id')
                                    ->get();
                foreach ($product_return_data as $index => $product_return) {
                    if($product_return->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_return->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($sale->grand_total, cache()->get('general_setting')->decimal);
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

    public function customerGroupReport(Request $request)
    {
        $customer_group_id = $request->input('customer_group_id');
        if($request->input('starting_date')) {
            $starting_date = $request->input('starting_date');
            $ending_date = $request->input('ending_date');
        }
        else {
            $starting_date = date("Y-m-d", strtotime(date('Y-m-d', strtotime('-1 year', strtotime(date('Y-m-d') )))));
            $ending_date = date("Y-m-d");
        }
        $lims_customer_group_list = CustomerGroup::where('is_active', true)->get();
        return view('backend.report.customer_group_report',compact('starting_date', 'ending_date', 'customer_group_id', 'lims_customer_group_list'));
    }

    public function customerGroupSaleData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $customer_group_id = $request->input('customer_group_id');
        $customer_ids = Customer::where('customer_group_id', $customer_group_id)->pluck('id');
        $q = DB::table('sales')
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->join('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.customer_id', $customer_ids)
            ->whereDate('sales.created_at', '>=' ,$request->input('starting_date'))
            ->whereDate('sales.created_at', '<=' ,$request->input('ending_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'sales.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('sales.id', 'sales.reference_no', 'sales.grand_total', 'sales.paid_amount', 'sales.sale_status', 'sales.created_at', 'customers.name as customer_name', 'customers.phone_number as customer_number', 'warehouses.name as warehouse_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $sales = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('sales.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $sales =  $q->orwhere([
                                ['sales.reference_no', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['sales.created_at', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['sales.reference_no', 'LIKE', "%{$search}%"],
                                    ['sales.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['sales.created_at', 'LIKE', "%{$search}%"],
                                    ['sales.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $sales =  $q->orwhere('sales.created_at', 'LIKE', "%{$search}%")->orwhere('sales.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('sales.created_at', 'LIKE', "%{$search}%")->orwhere('sales.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($sales))
        {
            foreach ($sales as $key => $sale)
            {
                $nestedData['id'] = $sale->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($sale->created_at));
                $nestedData['reference_no'] = $sale->reference_no;
                $nestedData['warehouse'] = $sale->warehouse_name;
                $nestedData['customer'] = $sale->customer_name.' ['.($sale->customer_number).']';
                $product_sale_data = DB::table('sales')->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')
                                    ->join('products', 'product_sales.product_id', '=', 'products.id')
                                    ->where('sales.id', $sale->id)
                                    ->whereNull('sales.deleted_at')
                                    ->select('products.name as product_name', 'product_sales.qty', 'product_sales.sale_unit_id')
                                    ->get();
                foreach ($product_sale_data as $index => $product_sale) {
                    if($product_sale->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_sale->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_sale->product_name.' ('.number_format($product_sale->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_sale->product_name.' ('.number_format($product_sale->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($sale->grand_total, cache()->get('general_setting')->decimal);
                $nestedData['paid'] = number_format($sale->paid_amount, cache()->get('general_setting')->decimal);
                $nestedData['due'] = number_format($sale->grand_total - $sale->paid_amount, cache()->get('general_setting')->decimal);
                if($sale->sale_status == 1){
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Completed').'</div>';
                    $sale_status = __('db.Completed');
                }
                elseif($sale->sale_status == 2){
                    $nestedData['sale_status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                    $sale_status = __('db.Pending');
                }
                else{
                    $nestedData['sale_status'] = '<div class="badge badge-warning">'.__('db.Draft').'</div>';
                    $sale_status = __('db.Draft');
                }
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

    public function customerGroupPaymentData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $customer_group_id = $request->input('customer_group_id');
        $customer_ids = Customer::where('customer_group_id', $customer_group_id)->pluck('id');
        $q = DB::table('payments')
           ->join('sales', 'payments.sale_id', '=', 'sales.id')
           ->join('customers', 'customers.id', '=', 'sales.customer_id')
           ->whereNull('sales.deleted_at')
           ->whereIn('sales.customer_id', $customer_ids)
           ->whereDate('payments.created_at', '>=' , $request->input('starting_date'))
           ->whereDate('payments.created_at', '<=' , $request->input('ending_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'sales.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('payments.*', 'sales.reference_no as sale_reference', 'customers.name as customer_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $payments = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('payments.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $payments =  $q->orwhere([
                                ['payments.payment_reference', 'LIKE', "%{$search}%"],
                                ['payments.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['payments.created_at', 'LIKE', "%{$search}%"],
                                ['payments.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['payments.payment_reference', 'LIKE', "%{$search}%"],
                                    ['payments.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['payments.created_at', 'LIKE', "%{$search}%"],
                                    ['payments.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $payments =  $q->orwhere('payments.created_at', 'LIKE', "%{$search}%")->orwhere('payments.payment_reference', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('payments.created_at', 'LIKE', "%{$search}%")->orwhere('payments.payment_reference', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($payments))
        {
            foreach ($payments as $key => $payment)
            {
                $nestedData['id'] = $payment->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($payment->created_at));
                $nestedData['reference_no'] = $payment->payment_reference;
                $nestedData['sale_reference'] = $payment->sale_reference;
                $nestedData['customer'] = $payment->customer_name;
                $nestedData['amount'] = number_format($payment->amount, cache()->get('general_setting')->decimal);
                $nestedData['paying_method'] = $payment->paying_method;
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

    public function customerGroupQuotationData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $customer_group_id = $request->input('customer_group_id');
        $customer_ids = Customer::where('customer_group_id', $customer_group_id)->pluck('id');
        $q = DB::table('quotations')
            ->join('customers', 'quotations.customer_id', '=', 'customers.id')
            ->leftJoin('suppliers', 'quotations.supplier_id', '=', 'suppliers.id')
            ->join('warehouses', 'quotations.warehouse_id', '=', 'warehouses.id')
            ->whereIn('quotations.customer_id', $customer_ids)
            ->whereDate('quotations.created_at', '>=' ,$request->input('starting_date'))
            ->whereDate('quotations.created_at', '<=' ,$request->input('ending_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'quotations.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('quotations.id', 'quotations.reference_no', 'quotations.supplier_id', 'quotations.grand_total', 'quotations.quotation_status', 'quotations.created_at', 'customers.name as customer_name', 'customers.phone_number as customer_number', 'suppliers.name as supplier_name', 'warehouses.name as warehouse_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $quotations = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('quotations.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $quotations =  $q->orwhere([
                                ['quotations.reference_no', 'LIKE', "%{$search}%"],
                                ['quotations.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['quotations.created_at', 'LIKE', "%{$search}%"],
                                ['quotations.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['quotations.reference_no', 'LIKE', "%{$search}%"],
                                    ['quotations.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['quotations.created_at', 'LIKE', "%{$search}%"],
                                    ['quotations.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $quotations =  $q->orwhere('quotations.created_at', 'LIKE', "%{$search}%")->orwhere('quotations.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('quotations.created_at', 'LIKE', "%{$search}%")->orwhere('quotations.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($quotations))
        {
            foreach ($quotations as $key => $quotation)
            {
                $nestedData['id'] = $quotation->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($quotation->created_at));
                $nestedData['reference_no'] = $quotation->reference_no;
                $nestedData['warehouse'] = $quotation->warehouse_name;
                $nestedData['customer'] = $quotation->customer_name.' ['.($quotation->customer_number).']';
                if($quotation->supplier_id) {
                    $nestedData['supplier'] = $quotation->supplier_name;
                }
                else
                    $nestedData['supplier'] = 'N/A';
                $product_quotation_data = DB::table('quotations')->join('product_quotation', 'quotations.id', '=', 'product_quotation.quotation_id')
                                    ->join('products', 'product_quotation.product_id', '=', 'products.id')
                                    ->where('quotations.id', $quotation->id)
                                    ->select('products.name as product_name', 'product_quotation.qty', 'product_quotation.sale_unit_id')
                                    ->get();
                foreach ($product_quotation_data as $index => $product_return) {
                    if($product_return->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_return->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($quotation->grand_total, cache()->get('general_setting')->decimal);
                if($quotation->quotation_status == 1){
                    $nestedData['status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                }
                else{
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Sent').'</div>';
                }
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

    public function customerGroupReturnData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $customer_group_id = $request->input('customer_group_id');
        $customer_ids = Customer::where('customer_group_id', $customer_group_id)->pluck('id');
        $q = DB::table('returns')
            ->join('customers', 'returns.customer_id', '=', 'customers.id')
            ->join('warehouses', 'returns.warehouse_id', '=', 'warehouses.id')
            ->whereIn('returns.customer_id', $customer_ids)
            ->whereDate('returns.created_at', '>=' ,$request->input('starting_date'))
            ->whereDate('returns.created_at', '<=' ,$request->input('ending_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'returns.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('returns.id', 'returns.reference_no', 'returns.grand_total', 'returns.created_at', 'customers.name as customer_name', 'customers.phone_number as customer_number', 'warehouses.name as warehouse_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $returns = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('returns.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $returns =  $q->orwhere([
                                ['returns.reference_no', 'LIKE', "%{$search}%"],
                                ['returns.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['returns.created_at', 'LIKE', "%{$search}%"],
                                ['returns.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['returns.reference_no', 'LIKE', "%{$search}%"],
                                    ['returns.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['returns.created_at', 'LIKE', "%{$search}%"],
                                    ['returns.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $returns =  $q->orwhere('returns.created_at', 'LIKE', "%{$search}%")->orwhere('returns.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('returns.created_at', 'LIKE', "%{$search}%")->orwhere('returns.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($returns))
        {
            foreach ($returns as $key => $sale)
            {
                $nestedData['id'] = $sale->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($sale->created_at));
                $nestedData['reference_no'] = $sale->reference_no;
                $nestedData['warehouse'] = $sale->warehouse_name;
                $nestedData['customer'] = $sale->customer_name.' ['.($sale->customer_number).']';
                $product_return_data = DB::table('returns')->join('product_returns', 'returns.id', '=', 'product_returns.return_id')
                                    ->join('products', 'product_returns.product_id', '=', 'products.id')
                                    ->where('returns.id', $sale->id)
                                    ->select('products.name as product_name', 'product_returns.qty', 'product_returns.sale_unit_id')
                                    ->get();
                foreach ($product_return_data as $index => $product_return) {
                    if($product_return->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_return->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($sale->grand_total, cache()->get('general_setting')->decimal);
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

    // public function supplierReport(Request $request)
    // {
    //     $data = $request->all();
    //     $supplier_id = $data['supplier_id'];
    //     $start_date = $data['start_date'];
    //     $end_date = $data['end_date'];
    //     $lims_purchase_data = Purchase::with('warehouse')->where('supplier_id', $supplier_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->orderBy('created_at', 'desc')->get();
    //     $lims_quotation_data = Quotation::with('warehouse', 'customer')->where('supplier_id', $supplier_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->orderBy('created_at', 'desc')->get();
    //     $lims_return_data = ReturnPurchase::with('warehouse')->where('supplier_id', $supplier_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->orderBy('created_at', 'desc')->get();
    //     $lims_payment_data = DB::table('payments')
    //                        ->join('purchases', 'payments.purchase_id', '=', 'purchases.id')
    //                        ->where('supplier_id', $supplier_id)
    //                        ->whereDate('payments.created_at', '>=' , $start_date)
    //                        ->whereDate('payments.created_at', '<=' , $end_date)
    //                        ->select('payments.*', 'purchases.reference_no as purchase_reference')
    //                        ->orderBy('payments.created_at', 'desc')
    //                        ->get();

    //     $lims_product_purchase_data = [];
    //     $lims_product_quotation_data = [];
    //     $lims_product_return_data = [];

    //     foreach ($lims_purchase_data as $key => $purchase) {
    //         $lims_product_purchase_data[$key] = ProductPurchase::where('purchase_id', $purchase->id)->get();
    //     }
    //     foreach ($lims_return_data as $key => $return) {
    //         $lims_product_return_data[$key] = PurchaseProductReturn::where('return_id', $return->id)->get();
    //     }
    //     foreach ($lims_quotation_data as $key => $quotation) {
    //         $lims_product_quotation_data[$key] = ProductQuotation::where('quotation_id', $quotation->id)->get();
    //     }
    //     $lims_supplier_list = Supplier::where('is_active', true)->get();
    //     return view('backend.report.supplier_report', compact('lims_purchase_data', 'lims_product_purchase_data', 'lims_payment_data', 'supplier_id', 'start_date', 'end_date', 'lims_supplier_list', 'lims_quotation_data', 'lims_product_quotation_data', 'lims_return_data', 'lims_product_return_data'));
    // }

    public function supplierReport(Request $request)
    {
        $supplier_id = $request->input('supplier_id');
        if($request->input('start_date')) {
            $start_date = $request->input('start_date');
            $end_date = $request->input('end_date');
        }
        else {
            $start_date = date("Y-m-d", strtotime(date('Y-m-d', strtotime('-1 year', strtotime(date('Y-m-d') )))));
            $end_date = date("Y-m-d");
        }
        $lims_supplier_list = Supplier::where('is_active', true)->get();
        return view('backend.report.supplier_report',compact('start_date', 'end_date', 'supplier_id', 'lims_supplier_list'));
    }

    public function supplierPurchaseData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $supplier_id = $request->input('supplier_id');
        $q = DB::table('purchases')
            ->join('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->join('warehouses', 'purchases.warehouse_id', '=', 'warehouses.id')
            ->where('purchases.supplier_id', $supplier_id)
            ->whereNull('purchases.deleted_at')
            ->whereDate('purchases.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('purchases.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'purchases.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('purchases.id', 'purchases.reference_no', 'purchases.grand_total', 'purchases.paid_amount', 'purchases.status', 'purchases.created_at', 'warehouses.name as warehouse_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $purchases = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('purchases.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $purchases =  $q->orwhere([
                                ['purchases.reference_no', 'LIKE', "%{$search}%"],
                                ['purchases.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['purchases.created_at', 'LIKE', "%{$search}%"],
                                ['purchases.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['purchases.reference_no', 'LIKE', "%{$search}%"],
                                    ['purchases.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['purchases.created_at', 'LIKE', "%{$search}%"],
                                    ['purchases.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $purchases =  $q->orwhere('purchases.created_at', 'LIKE', "%{$search}%")->orwhere('purchases.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('purchases.created_at', 'LIKE', "%{$search}%")->orwhere('purchases.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($purchases))
        {
            foreach ($purchases as $key => $purchase)
            {
                $nestedData['id'] = $purchase->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($purchase->created_at));
                $nestedData['reference_no'] = $purchase->reference_no;
                $nestedData['warehouse'] = $purchase->warehouse_name;
                $product_purchase_data = DB::table('purchases')->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')
                                    ->join('products', 'product_purchases.product_id', '=', 'products.id')
                                    ->where('purchases.id', $purchase->id)
                                    ->whereNull('purchases.deleted_at')
                                    ->select('products.name as product_name', 'product_purchases.qty', 'product_purchases.purchase_unit_id')
                                    ->get();
                foreach ($product_purchase_data as $index => $product_purchase) {
                    if($product_purchase->purchase_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_purchase->purchase_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_purchase->product_name.' ('.number_format($product_purchase->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_purchase->product_name.' ('.number_format($product_purchase->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($purchase->grand_total, cache()->get('general_setting')->decimal);
                $nestedData['paid'] = number_format($purchase->paid_amount, cache()->get('general_setting')->decimal);
                $nestedData['balance'] = number_format($purchase->grand_total - $purchase->paid_amount, cache()->get('general_setting')->decimal);
                if($purchase->status == 1){
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Completed').'</div>';
                    $status = __('db.Completed');
                }
                elseif($purchase->status == 2){
                    $nestedData['status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                    $status = __('db.Pending');
                }
                else{
                    $nestedData['status'] = '<div class="badge badge-warning">'.__('db.Draft').'</div>';
                    $status = __('db.Draft');
                }
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

    public function supplierPaymentData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $supplier_id = $request->input('supplier_id');
        $q = DB::table('payments')
           ->join('purchases', 'payments.purchase_id', '=', 'purchases.id')
           ->where('purchases.supplier_id', $supplier_id)
           ->whereNull('purchases.deleted_at')
           ->whereDate('payments.created_at', '>=' , $request->input('start_date'))
           ->whereDate('payments.created_at', '<=' , $request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'payments.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('payments.*', 'purchases.reference_no as purchase_reference')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $payments = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('payments.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $payments =  $q->orwhere([
                                ['payments.payment_reference', 'LIKE', "%{$search}%"],
                                ['payments.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['payments.created_at', 'LIKE', "%{$search}%"],
                                ['payments.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['payments.payment_reference', 'LIKE', "%{$search}%"],
                                    ['payments.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['payments.created_at', 'LIKE', "%{$search}%"],
                                    ['payments.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $payments =  $q->orwhere('payments.created_at', 'LIKE', "%{$search}%")->orwhere('payments.payment_reference', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('payments.created_at', 'LIKE', "%{$search}%")->orwhere('payments.payment_reference', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($payments))
        {
            foreach ($payments as $key => $payment)
            {
                $nestedData['id'] = $payment->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($payment->created_at));
                $nestedData['reference_no'] = $payment->payment_reference;
                $nestedData['purchase_reference'] = $payment->purchase_reference;
                $nestedData['amount'] = number_format($payment->amount, cache()->get('general_setting')->decimal);
                $nestedData['paying_method'] = $payment->paying_method;
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

    public function supplierReturnData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $supplier_id = $request->input('supplier_id');
        $q = DB::table('return_purchases')
            ->join('suppliers', 'return_purchases.supplier_id', '=', 'suppliers.id')
            ->join('warehouses', 'return_purchases.warehouse_id', '=', 'warehouses.id')
            ->where('return_purchases.supplier_id', $supplier_id)
            ->whereDate('return_purchases.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('return_purchases.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'return_purchases.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('return_purchases.id', 'return_purchases.reference_no', 'return_purchases.grand_total', 'return_purchases.created_at', 'warehouses.name as warehouse_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $return_purchases = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('return_purchases.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $return_purchases =  $q->orwhere([
                                ['return_purchases.reference_no', 'LIKE', "%{$search}%"],
                                ['return_purchases.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['return_purchases.created_at', 'LIKE', "%{$search}%"],
                                ['return_purchases.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['return_purchases.reference_no', 'LIKE', "%{$search}%"],
                                    ['return_purchases.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['return_purchases.created_at', 'LIKE', "%{$search}%"],
                                    ['return_purchases.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $return_purchases =  $q->orwhere('return_purchases.created_at', 'LIKE', "%{$search}%")->orwhere('return_purchases.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('return_purchases.created_at', 'LIKE', "%{$search}%")->orwhere('return_purchases.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($return_purchases))
        {
            foreach ($return_purchases as $key => $return)
            {
                $nestedData['id'] = $return->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($return->created_at));
                $nestedData['reference_no'] = $return->reference_no;
                $nestedData['warehouse'] = $return->warehouse_name;
                $product_return_data = DB::table('return_purchases')->join('purchase_product_return', 'return_purchases.id', '=', 'purchase_product_return.return_id')
                                    ->join('products', 'purchase_product_return.product_id', '=', 'products.id')
                                    ->where('return_purchases.id', $return->id)
                                    ->select('products.name as product_name', 'purchase_product_return.qty', 'purchase_product_return.purchase_unit_id')
                                    ->get();
                foreach ($product_return_data as $index => $product_return) {
                    if($product_return->purchase_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_return->purchase_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($return->grand_total, cache()->get('general_setting')->decimal);
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

    public function supplierQuotationData(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $supplier_id = $request->input('supplier_id');
        $q = DB::table('quotations')
            ->join('suppliers', 'quotations.supplier_id', '=', 'suppliers.id')
            ->leftJoin('customers', 'quotations.customer_id', '=', 'customers.id')
            ->join('warehouses', 'quotations.warehouse_id', '=', 'warehouses.id')
            ->where('quotations.supplier_id', $supplier_id)
            ->whereDate('quotations.created_at', '>=' ,$request->input('start_date'))
            ->whereDate('quotations.created_at', '<=' ,$request->input('end_date'));

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start_date');
        $order = 'quotations.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $q = $q->select('quotations.id', 'quotations.reference_no', 'quotations.supplier_id', 'quotations.grand_total', 'quotations.quotation_status', 'quotations.created_at', 'customers.name as customer_name', 'warehouses.name as warehouse_name')
            ->offset($start)
            ->limit($limit)
            ->orderBy($order, $dir);
        if(empty($request->input('search.value'))) {
            $quotations = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = $q->whereDate('quotations.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))));
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $quotations =  $q->orwhere([
                                ['quotations.reference_no', 'LIKE', "%{$search}%"],
                                ['quotations.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['quotations.created_at', 'LIKE', "%{$search}%"],
                                ['quotations.user_id', Auth::id()]
                            ])
                            ->get();
                $totalFiltered = $q->orwhere([
                                    ['quotations.reference_no', 'LIKE', "%{$search}%"],
                                    ['quotations.user_id', Auth::id()]
                                ])
                                ->orwhere([
                                    ['quotations.created_at', 'LIKE', "%{$search}%"],
                                    ['quotations.user_id', Auth::id()]
                                ])
                                ->count();
            }
            else {
                $quotations =  $q->orwhere('quotations.created_at', 'LIKE', "%{$search}%")->orwhere('quotations.reference_no', 'LIKE', "%{$search}%")->get();
                $totalFiltered = $q->orwhere('quotations.created_at', 'LIKE', "%{$search}%")->orwhere('quotations.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if(!empty($quotations))
        {
            foreach ($quotations as $key => $quotation)
            {
                $nestedData['id'] = $quotation->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($quotation->created_at));
                $nestedData['reference_no'] = $quotation->reference_no;
                $nestedData['warehouse'] = $quotation->warehouse_name;
                $nestedData['customer'] = $quotation->customer_name;
                $product_quotation_data = DB::table('quotations')->join('product_quotation', 'quotations.id', '=', 'product_quotation.quotation_id')
                                    ->join('products', 'product_quotation.product_id', '=', 'products.id')
                                    ->where('quotations.id', $quotation->id)
                                    ->select('products.name as product_name', 'product_quotation.qty', 'product_quotation.sale_unit_id')
                                    ->get();
                foreach ($product_quotation_data as $index => $product_return) {
                    if($product_return->sale_unit_id) {
                        $unit_data = DB::table('units')->select('unit_code')->find($product_return->sale_unit_id);
                        $unitCode = $unit_data->unit_code;
                    }
                    else
                        $unitCode = '';
                    if($index)
                        $nestedData['product'] .= '<br>'.$product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                    else
                        $nestedData['product'] = $product_return->product_name.' ('.number_format($product_return->qty, cache()->get('general_setting')->decimal).' '.$unitCode.')';
                }
                $nestedData['grand_total'] = number_format($quotation->grand_total, cache()->get('general_setting')->decimal);
                if($quotation->quotation_status == 1){
                    $nestedData['status'] = '<div class="badge badge-danger">'.__('db.Pending').'</div>';
                }
                else{
                    $nestedData['status'] = '<div class="badge badge-success">'.__('db.Sent').'</div>';
                }
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

    public function customerDueReportByDate(Request $request)
    {
        $data = $request->all();
        $start_date = $data['start_date'];
        $end_date = $data['end_date'];
        $customer_id = $request->customer_id ?? 0;

        // $q = Sale::where('payment_status', '!=', 4)
        //     ->whereDate('created_at', '>=' , $start_date)
        //     ->whereDate('created_at', '<=' , $end_date);
        // if($request->customer_id)
        //     $q = $q->where('customer_id', $request->customer_id);
        $lims_sale_data = [];
        if ($customer_id) {
            $lims_sale_data = Sale::where('payment_status', '!=', 4)
                ->whereNull('deleted_at')
                ->where('customer_id', $request->customer_id)
                ->whereDate('created_at', '>=' , $start_date)
                ->whereDate('created_at', '<=' , $end_date)
                ->get();
        } else {
            $lims_sale_data = Sale::where('payment_status', '!=', 4)
                ->whereNull('deleted_at')
                ->whereDate('created_at', '>=' , $start_date)
                ->whereDate('created_at', '<=' , $end_date)
                ->get();
        }
        // return dd($lims_sale_data);
        return view('backend.report.due_report', compact('lims_sale_data', 'start_date', 'end_date', 'customer_id'));
    }

    public function customerDueReportData(Request $request)
    {
        $columns = [
            1 => 'created_at',
            2 => 'reference_no',
        ];

        $baseQuery = DB::table('sales')
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->where('payment_status', '!=', 4)
            ->whereNull('sales.deleted_at')
            ->whereDate('sales.created_at', '>=', $request->input('start_date'))
            ->whereDate('sales.created_at', '<=', $request->input('end_date'));

        // Apply customer_id filter if present and not zero
        if ($request->filled('customer_id') && $request->customer_id != 0) {
            $baseQuery->where('sales.customer_id', $request->customer_id);
        }

        $totalDataQuery = clone $baseQuery;
        $filteredDataQuery = clone $baseQuery;

        $totalData = $totalDataQuery->count();

        $search = $request->input('search.value');
        if (!empty($search)) {
            $searchDate = date('Y-m-d', strtotime(str_replace('/', '-', $search)));

            $filteredDataQuery->where(function ($query) use ($search, $searchDate) {
                $query->whereDate('sales.created_at', $searchDate)
                    ->whereNull('sales.deleted_at')
                    ->orWhere('sales.reference_no', 'LIKE', "%{$search}%")
                    ->orWhere('customers.name', 'LIKE', "%{$search}%")
                    ->orWhere('customers.phone_number', 'LIKE', "%{$search}%");
            });

            if (Auth::user()->role_id > 2 && config('staff_access') === 'own') {
                $filteredDataQuery->where('sales.user_id', Auth::id());
            }
        }

        $totalFiltered = $filteredDataQuery->count();

        $limit = $request->input('length') != -1 ? intval($request->input('length')) : $totalFiltered;
        $start = intval($request->input('start'));
        $orderColumnIndex = intval($request->input('order.0.column'));
        $orderDir = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';
        $orderColumn = isset($columns[$orderColumnIndex]) ? 'sales.' . $columns[$orderColumnIndex] : 'sales.created_at';

        $sales = $filteredDataQuery
            ->select(
                'sales.id',
                'sales.reference_no',
                'sales.grand_total',
                'sales.created_at',
                'sales.paid_amount',
                'customers.name as customer_name',
                'customers.phone_number as customer_phone_number'
            )
            ->orderBy($orderColumn, $orderDir)
            ->offset($start)
            ->limit($limit)
            ->get();

        $data = [];
        foreach ($sales as $key => $sale) {
            $returned_amount = DB::table('returns')->where('sale_id', $sale->id)->sum(DB::raw('grand_total / exchange_rate'));

            $data[] = [
                'id' => $sale->id,
                'key' => $key,
                'date' => date(config('date_format'), strtotime($sale->created_at)),
                'reference_no' => $sale->reference_no,
                'customer' => $sale->customer_name . ' (' . $sale->customer_phone_number . ')',
                'grand_total' => number_format($sale->grand_total, cache()->get('general_setting')->decimal),
                'returned_amount' => number_format($returned_amount, cache()->get('general_setting')->decimal),
                'paid' => number_format($sale->paid_amount ?? 0, cache()->get('general_setting')->decimal),
                'due' => number_format(($sale->grand_total - $returned_amount - ($sale->paid_amount ?? 0)), cache()->get('general_setting')->decimal),
            ];
        }

        return response()->json([
            "draw" => intval($request->input('draw')),
            "recordsTotal" => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data" => $data,
        ]);
    }


    public function supplierDueReportByDate(Request $request)
    {
        $data = $request->all();
        $supplier_id = null;
        $start_date = $data['start_date'];
        $end_date = $data['end_date'];
        $q = Purchase::where('payment_status', 1)
            ->whereNull('deleted_at')
            ->whereDate('updated_at', '>=' , $start_date)
            ->whereDate('updated_at', '<=' , $end_date);
        if($request->supplier_id) {
            $supplier_id = $request->supplier_id;
            $q = $q->where('supplier_id', $request->supplier_id);
        }
        $lims_purchase_data = $q->get();
        $lims_supplier_list = Supplier::where('is_active', true)->get();

        return view('backend.report.supplier_due_report', compact('lims_purchase_data', 'start_date', 'end_date', 'lims_supplier_list', 'supplier_id'));
    }
}
