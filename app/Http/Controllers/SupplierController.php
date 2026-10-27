<?php

namespace App\Http\Controllers;

use Mail;
use App\Models\Account;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\MailSetting;
use App\Mail\CustomerCreate;
use App\Mail\SupplierCreate;
use App\Models\CashRegister;
use Illuminate\Http\Request;
use App\Models\CustomerGroup;
use App\Models\PurchaseProductReturn;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;

class SupplierController extends Controller
{
    use \App\Traits\MailInfo;
    use \App\Http\Controllers\Concerns\NumbersLegacyDocuments;

    public function index()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('suppliers-index')){
            $permissions = Role::findByName($role->name)->permissions;
            foreach ($permissions as $permission)
                $all_permission[] = $permission->name;
            if(empty($all_permission))
                $all_permission[] = 'dummy text';
            $lims_supplier_all = Supplier::where('is_active', true)->get();
            return view('backend.supplier.index',compact('lims_supplier_all', 'all_permission'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function clearDue(Request $request)
    {
        return \Illuminate\Support\Facades\DB::transaction(fn () => $this->settleSupplierDue($request));
    }

    private function settleSupplierDue(Request $request)
    {
        $lims_due_purchase_data = Purchase::select('id', 'warehouse_id', 'grand_total', 'paid_amount', 'payment_status')
                            ->where([
                                ['payment_status', 1],
                                ['supplier_id', $request->supplier_id]
                            ])->get();
        $total_paid_amount = $request->amount;
        foreach ($lims_due_purchase_data as $key => $purchase_data) {
            if($total_paid_amount == 0)
                break;
            $due_amount = $purchase_data->grand_total - $purchase_data->paid_amount;

            if($request->cash_register)
                $cash_register_id = $request->cash_register;
            else
                $cash_register_id = null;
            $account_data = Account::select('id')->where('is_default', 1)->first();
            if($total_paid_amount >= $due_amount) {
                $paid_amount = $due_amount;
                $payment_status = 2;
            }
            else {
                $paid_amount = $total_paid_amount;
                $payment_status = 1;
            }
            $paymentReservation = $this->reserveNumber('purchase_payment');
            $payment = Payment::create([
                'payment_reference' => $paymentReservation->formatted_number,
                'purchase_id' => $purchase_data->id,
                'user_id' => Auth::id(),
                'cash_register_id' => $cash_register_id,
                'account_id' => $account_data->id,
                'amount' => $paid_amount,
                'change' => 0,
                'paying_method' => 'Cash',
                'payment_note' => $request->note
            ]);
            $this->assignNumber($paymentReservation, $payment);
            $purchase_data->paid_amount += $paid_amount;
            $purchase_data->payment_status = $payment_status;
            $purchase_data->save();
            $total_paid_amount -= $paid_amount;
        }
        return redirect()->back()->with('message', __('db.Due cleared successfully'));
    }

    public function create()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('suppliers-add')){
            $lims_customer_group_all = CustomerGroup::where('is_active',true)->get();
            return view('backend.supplier.create', compact('lims_customer_group_all') + $this->formMasters());
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'company_name' => \App\Support\CompanyUnique::rules('suppliers'),
            'email' => \App\Support\CompanyUnique::rules('suppliers'),
            'image' => 'image|mimes:jpg,jpeg,png,gif|max:100000',
            'vat_number' => \App\Services\Tax\Gstin::rule(),
        ]);

        //validation for customer if create both user and supplier
        if(isset($request->both)) {
            $this->validate($request, [
                'phone_number' => \App\Support\CompanyUnique::rules('customers'),
            ]);
        }

        $request->validate(['opening_balance' => 'nullable|numeric|min:0'] + Supplier::purchaseDefaultRules());
        $openingBalance = (float) ($request->opening_balance ?? 0);
        $context = app(\App\Services\Platform\CompanyContextResolver::class)->forActor();
        $openings = app(\App\Services\Accounting\PartyOpeningBalanceService::class);
        $openings->assertPostable('supplier', $openingBalance, $context);

        $lims_supplier_data = $request->except('image');
        $lims_supplier_data['is_active'] = true;
        $lims_supplier_data['purchase_defaults'] = Supplier::cleanPurchaseDefaults($request->input('purchase_defaults'));
        $image = $request->image;
        if ($image) {
            $imageName = app(\App\Services\Documents\PrivateFileStorage::class)->store($image, 'supplier');
            $lims_supplier_data['image'] = $imageName;
        }
        // The opening due posts as an open item on Creditors, dated the financial year start.
        $create_supplier = DB::transaction(function () use ($lims_supplier_data, $openingBalance, $openings, $context) {
            $supplier = Supplier::create($lims_supplier_data);
            $openings->post($supplier, $openingBalance, $context);
            return $supplier;
        });

        $message = 'Supplier';
        if(isset($request->both)) {
            // The due entered here is the supplier's; the customer copy starts clear.
            Customer::create(['opening_balance' => 0] + $lims_supplier_data);
            $message .= ' and Customer';
        }
        $mail_setting = MailSetting::latest()->first();
        if(!empty($lims_supplier_data['email']) && $mail_setting) {
            $this->setMailInfo($mail_setting);
            try {
                Mail::to($lims_supplier_data['email'])->send(new SupplierCreate($lims_supplier_data));
                if(isset($request->both))
                    Mail::to($lims_supplier_data['email'])->send(new CustomerCreate($lims_supplier_data));
                $message .= ' created successfully!';
            }
            catch(\Exception $e) {
                $message .= ' created successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }
        return redirect('supplier')->with('message', $message);
    }

    public function show($id)
    {
        // Permission check (simplified)
        // if (!auth()->user()->can('suppliers-index')) {
        //     return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
        // }

        $supplier = Supplier::findOrFail($id);

        $opening_balance = $supplier->opening_balance ?? 0;

        // Total purchase from supplier
        $total_purchases = Purchase::where('supplier_id', $id)->sum('grand_total');

        // Total paid to supplier
        $total_paid = Payment::where('user_id', $id)->sum('amount');

        // Balance formula
        $balance_due = ($opening_balance + $total_purchases) - $total_paid;

        return view('backend.supplier.view', [
            'lims_supplier_data' => $supplier,
            'opening_balance' => $opening_balance,
            'total_purchase' => $total_purchases,
            'total_paid' => $total_paid,
            'balance_due' => $balance_due,
        ]);
    }

    public function ledger($id)
    {
        // Supplier Purchases
        $purchases = Purchase::where('supplier_id', $id)->get()->map(function ($p) {
            return [
                'date'      => $p->date ?? $p->created_at->format('Y-m-d'),
                'type'      => 'Purchase',
                'reference' => $p->reference_no,
                'debit'     => floatval($p->grand_total), // increase payable
                'credit'    => 0,
            ];
        });

        // Supplier Payments (must check correct column)
        $payments = Payment::where('user_id', $id)->get()->map(function ($p) {
            return [
                'date'      => $p->date ?? $p->created_at->format('Y-m-d'),
                'type'      => 'Payment',
                'reference' => $p->payment_reference ?? '-',
                'debit'     => 0,
                'credit'    => floatval($p->amount),
            ];
        });

        // Supplier Returns (no supplier_id directly → join through purchase)
        $returns = PurchaseProductReturn::whereHas('purchaseReturn.purchase', function ($q) use ($id) {
                $q->where('supplier_id', $id);
            })
            ->get()
            ->map(function ($r) {
                return [
                    'date'      => $r->purchaseReturn->date ?? $r->purchaseReturn->created_at->format('Y-m-d'),
                    'type'      => 'Purchase Return',
                    'reference' => $r->purchaseReturn->return_reference ?? '-',
                    'debit'     => 0,
                    'credit'    => floatval($r->purchaseReturn->grand_total),
                ];
            });

        // Merge All
        $ledger = $purchases->merge($payments)->merge($returns)->sortBy('date')->values()->toArray();

        // Running Balance
        $balance = 0;
        foreach ($ledger as $key => $row) {
            $balance += ($row['debit'] - $row['credit']); // debit increases payable
            $ledger[$key]['balance'] = number_format($balance, 2);
        }

        return response()->json(['data' => $ledger]);
    }

    public function edit($id)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('suppliers-edit')){
            $lims_supplier_data = Supplier::findOrFail($id);
            return view('backend.supplier.edit', compact('lims_supplier_data') + $this->formMasters());
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    /** Pick lists for the supplier form: this company's areas and agents, and GST state names. */
    private function formMasters(): array
    {
        $context = request()->attributes->get(\App\Services\Platform\CompanyContext::class);
        $scoped = fn (string $model) => $model::when($context, fn ($q) => $q->where('company_id', $context->companyId))
            ->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return ['areas' => $scoped(\App\Models\Area::class), 'agents' => $scoped(\App\Models\Agent::class),
            'states' => \App\Services\Tax\Gstin::$states];
    }

    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'company_name' => \App\Support\CompanyUnique::rules('suppliers', (int) $id),

            'email' => \App\Support\CompanyUnique::rules('suppliers', (int) $id),
            'image' => 'image|mimes:jpg,jpeg,png,gif|max:100000',
            'vat_number' => \App\Services\Tax\Gstin::rule(),
        ] + Supplier::purchaseDefaultRules());

        $lims_supplier_data = Supplier::findOrFail($id);

        $input = $request->except('image');
        if ($request->has('purchase_defaults')) $input['purchase_defaults'] = Supplier::cleanPurchaseDefaults($request->input('purchase_defaults'));
        $image = $request->image;
        if ($image) {
            $oldImage = $lims_supplier_data->image;

            $imageName = app(\App\Services\Documents\PrivateFileStorage::class)->store($image, 'supplier');
            $input['image'] = $imageName;
        }

        $lims_supplier_data->update($input);
        if (isset($oldImage)) {
            app(\App\Services\Documents\PrivateFileStorage::class)->delete('supplier', $oldImage);
        }
        return redirect('supplier')->with('message', __('db.Data updated successfully'));
    }

    public function deleteBySelection(Request $request)
    {
        $supplier_id = $request['supplierIdArray'];
        foreach ($supplier_id as $id) {
            $lims_supplier_data = Supplier::findOrFail($id);
            $lims_supplier_data->is_active = false;
            $lims_supplier_data->save();
            app(\App\Services\Documents\PrivateFileStorage::class)->delete('supplier', $lims_supplier_data->image);
        }
        return 'Supplier deleted successfully!';
    }

    public function destroy($id)
    {
        $lims_supplier_data = Supplier::findOrFail($id);
        $lims_supplier_data->is_active = false;
        $lims_supplier_data->save();
        app(\App\Services\Documents\PrivateFileStorage::class)->delete('supplier', $lims_supplier_data->image);

        return redirect('supplier')->with('not_permitted', __('db.Data deleted successfully'));
    }

    public function importSupplier(Request $request)
    {
        $upload=$request->file('file');
        $ext = pathinfo($upload->getClientOriginalName(), PATHINFO_EXTENSION);
        if($ext != 'csv')
            return redirect()->back()->with('not_permitted', __('db.Please upload a CSV file'));
        $filename =  $upload->getClientOriginalName();
        $filePath=$upload->getRealPath();
        //open and read
        $file=fopen($filePath, 'r');
        $header= fgetcsv($file);
        $escapedHeader=[];
        //validate
        foreach ($header as $key => $value) {
            $lheader=strtolower($value);
            $escapedItem=preg_replace('/[^a-z]/', '', $lheader);
            array_push($escapedHeader, $escapedItem);
        }
        //looping through othe columns
        while($columns=fgetcsv($file))
        {
            if($columns[0]=="")
                continue;
            foreach ($columns as $key => $value) {
                $value=preg_replace('/\D/','',$value);
            }
           $data= array_combine($escapedHeader, $columns);

           $supplier = Supplier::firstOrNew(['company_name'=>$data['companyname']]);
           $supplier->name = $data['name'];
           $supplier->image = $data['image'];
           $supplier->vat_number = $data['vatnumber'];
           $supplier->email = $data['email'];
           $supplier->phone_number = $data['phonenumber'];
           $supplier->address = $data['address'];
           $supplier->city = $data['city'];
           $supplier->state = $data['state'];
           $supplier->postal_code = $data['postalcode'];
           $supplier->country = $data['country'];
           $supplier->is_active = true;
           $supplier->save();
           $message = 'Supplier Imported Successfully';

           $mail_setting = MailSetting::latest()->first();


           if($data['email'] && $mail_setting) {
                try {
                    Mail::to($data['email'])->send(new SupplierCreate($data));
                }
                catch(\Excetion $e){
                    $message = 'Supplier imported successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
                }
            }
        }
        return redirect('supplier')->with('message', $message);
    }

    public function suppliersAll()
    {
        $lims_supplier_list = DB::table('suppliers')->where('is_active', true)->get();
        
        $html = '';
        foreach($lims_supplier_list as $supplier){
            $html .='<option value="'.$supplier->id.'">'.$supplier->name . ' (' . $supplier->phone_number. ')'.'</option>';
        }

        return response()->json($html);
    }

    public function supplierDue($id)
    {
        $lims_due_purchase_data = Purchase::where([
                                        ['payment_status', 1],
                                        ['supplier_id', $id]
                                    ])->get();
        $due = 0;
        foreach ($lims_due_purchase_data as $key => $purchase_data) {
            $due += ($purchase_data->grand_total - $purchase_data->paid_amount);
        }

        $returned_amount = DB::table('purchases')
                                ->join('return_purchases', 'purchases.id', '=', 'return_purchases.purchase_id')
                                ->where([
                                    ['purchases.supplier_id', $id],
                                    ['purchases.payment_status', 1]
                                ])
                                ->whereNull('purchases.deleted_at')
                                ->sum('return_purchases.grand_total');
        $due -= $returned_amount;

        return response()->json([$due]);
    }

    public function supplierPayments($supplier_id)
    {
        $payments = DB::table('payments')
            ->join('purchases', 'payments.purchase_id', '=', 'purchases.id')
            ->where('purchases.supplier_id', $supplier_id)
            ->whereNull('purchases.deleted_at')
            ->select(
                'payments.id',
                'payments.created_at',
                'payments.payment_reference',
                'payments.amount',
                'payments.paying_method',
                'payments.payment_at'
            )
            ->latest('payments.created_at')
            ->get()
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'created_at' => $payment->created_at ? date('Y-m-d', strtotime($payment->created_at)) : '-',
                    'payment_reference' => $payment->payment_reference ?? '-',
                    'amount' => number_format($payment->amount, 2),
                    'paying_method' => ucfirst($payment->paying_method ?? '-'),
                    'payment_at' => $payment->payment_at ? date('Y-m-d H:i', strtotime($payment->payment_at)) : date('Y-m-d H:i', strtotime($payment->created_at)),
                ];
            });

        return response()->json(['data' => $payments]);
    }
}
