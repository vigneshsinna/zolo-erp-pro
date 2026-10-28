<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Account;
use App\Models\Payment;
use App\Models\Returns;
use App\Models\ReturnPurchase;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payroll;
use App\Models\MoneyTransfer;
use App\Models\Purchase;
use App\Models\Sale;
use DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Auth;
use Carbon\Carbon;

class AccountsController extends Controller
{
    public function index()
    {
        $role = Role::find(Auth::user()->role_id);
        if (!$role->hasPermissionTo('account-index')) {
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
        }

        $lims_account_all = Account::where('is_active', true)->get();

        foreach ($lims_account_all as $account) {

            // -------------------
            // CREDIT
            // -------------------
            $payment_received = Payment::whereNotNull('sale_id')
                ->where('account_id', $account->id)
                ->sum('amount');

            $return_purchase = DB::table('return_purchases')
                ->where('account_id', $account->id)
                ->sum('grand_total');

            $recieved_money_via_transfer = MoneyTransfer::where('to_account_id', $account->id)
                ->sum('amount');

            $income = Income::where('account_id', $account->id)
                ->sum('amount');

            $credit = $payment_received + $return_purchase + $recieved_money_via_transfer + ($account->initial_balance ?? 0) + $income;

            // -------------------
            // DEBIT
            // -------------------
            // Sales Return → due adjust + refund logic
            $sales_returns = Returns::with('sale')
                ->where('account_id', $account->id)
                ->get();

            $total_sales_return_debit = 0;
            foreach ($sales_returns as $return) {
                $sale = $return->sale;
                if (!$sale) continue;

                $sale_total  = $sale->grand_total;
                $paid        = $sale->paid_amount;
                $return_amt  = $return->grand_total;
                $due         = $sale_total - $paid;

                $due_adjust  = min($due, $return_amt);
                $refund      = $return_amt - $due_adjust;

                if ($refund > 0) {
                    $total_sales_return_debit += $refund;
                }
            }

            $payment_sent = Payment::whereNotNull('purchase_id')
                ->where('account_id', $account->id)
                ->sum('amount');

            $expenses = DB::table('expenses')
                ->where('account_id', $account->id)
                ->sum('amount');

            $payrolls = DB::table('payrolls')
                ->where('account_id', $account->id)
                ->sum('amount');

            $sent_money_via_transfer = MoneyTransfer::where('from_account_id', $account->id)
                ->sum('amount');

            $debit = $total_sales_return_debit + $payment_sent + $expenses + $payrolls + $sent_money_via_transfer;

            // -------------------
            // FINAL BALANCE
            // -------------------
            $account->balance = $credit - $debit;
        }

        return view('backend.account.index', compact('lims_account_all'));
    }



    public function store(Request $request)
    {
        $this->validate($request, [
            'account_no' => [
                'max:255',
                Rule::unique('accounts')->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
        ]);

        $lims_account_data = Account::where('is_active', true)->first();
        $data = $request->all();
        if ($data['initial_balance'])
            $data['total_balance'] = $data['initial_balance'];
        else
            $data['total_balance'] = 0;
        if (!$lims_account_data)
            $data['is_default'] = 1;
        $data['is_active'] = true;
        Account::create($data);
        return redirect('accounts')->with('message', __('db.Account created successfully'));
    }

    public function makeDefault($id)
    {
        $lims_account_data = Account::where('is_default', true)->first();
        $lims_account_data->is_default = false;
        $lims_account_data->save();

        $lims_account_data = Account::findOrFail($id);
        $lims_account_data->is_default = true;
        $lims_account_data->save();

        return 'Account set as default successfully';
    }

    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'account_no' => [
                'max:255',
                Rule::unique('accounts')->ignore($request->account_id)->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
        ]);

        $data = $request->all();
        $lims_account_data = Account::find($data['account_id']);
        if ($data['initial_balance'])
            $data['total_balance'] = $data['initial_balance'];
        else
            $data['total_balance'] = 0;
        $lims_account_data->update($data);
        return redirect('accounts')->with('message', __('db.Account updated successfully'));
    }

    public function accountStatement(Request $request)
    {
        $data = $request->all();

        $lims_account_data = Account::find($data['account_id']);
        $initial_balance =  $lims_account_data;

        $start_date = Carbon::parse($data['start_date'])->startOfDay();
        $end_date   = Carbon::parse($data['end_date'])->endOfDay();

        $balance = $initial_balance->initial_balance ?? 0;

        $account_statement_array = collect();

        // -----------------------------
        // CREDIT TRANSACTIONS
        // -----------------------------
        if ($data['type'] == '0' || $data['type'] == '2') {

            // Sale Payment
            $sale_payments = Payment::whereNotNull('sale_id')
                ->where('account_id', $data['account_id'])
                ->whereBetween('created_at', [$start_date, $end_date])
                ->get()
                ->map(function ($item) {
                    $item->type = 'credit';
                    return $item;
                });

            // Money Received
            $money_received = MoneyTransfer::where('to_account_id', $data['account_id'])
                ->whereBetween('created_at', [$start_date, $end_date])
                ->get()
                ->map(function ($item) {
                    $item->type = 'credit';
                    return $item;
                });

            // Purchase Return
            $purchase_return = ReturnPurchase::where('account_id', $data['account_id'])
                ->whereBetween('created_at', [$start_date, $end_date])
                ->get()
                ->map(function ($item) {
                    $item->type = 'credit';
                    return $item;
                });

            // Income
            $income = Income::where('account_id', $data['account_id'])
                ->whereBetween('created_at', [$start_date, $end_date])
                ->get()
                ->map(function ($item) {
                    $item->type = 'credit';
                    return $item;
                });

            $account_statement_array = $account_statement_array
                ->concat($sale_payments)
                ->concat($money_received)
                ->concat($purchase_return)
                ->concat($income);
        }

        // -----------------------------
        // DEBIT TRANSACTIONS
        // -----------------------------
        if ($data['type'] == '0' || $data['type'] == '1') {

            // Purchase Payment
            $purchase_payment = Payment::whereNotNull('purchase_id')
                ->where('account_id', $data['account_id'])
                ->whereBetween('created_at', [$start_date, $end_date])
                ->get()
                ->map(function ($item) {
                    $item->type = 'debit';
                    return $item;
                });

            // Expenses
            $expense = Expense::where('account_id', $data['account_id'])
                ->whereBetween('created_at', [$start_date, $end_date])
                ->get()
                ->map(function ($item) {
                    $item->type = 'debit';
                    return $item;
                });

            // Payroll
            $payroll = Payroll::where('account_id', $data['account_id'])
                ->whereBetween('created_at', [$start_date, $end_date])
                ->get()
                ->map(function ($item) {
                    $item->type = 'debit';
                    return $item;
                });

            // Money Sent
            $money_sent = MoneyTransfer::where('from_account_id', $data['account_id'])
                ->whereBetween('created_at', [$start_date, $end_date])
                ->get()
                ->map(function ($item) {
                    $item->type = 'debit';
                    return $item;
                });

            // Sales Return → DEBIT calculation
            $sales_returns = Returns::with('sale')
                ->where('account_id', $data['account_id'])
                ->whereBetween('created_at', [$start_date, $end_date])
                ->get()
                ->map(function ($return) {
                    $sale = $return->sale;
                    if (!$sale) return null;

                    $sale_total = $sale->grand_total;
                    $paid       = $sale->paid_amount;
                    $return_amt = $return->grand_total;
                    $due        = $sale_total - $paid;

                    $due_adjust = min($due, $return_amt);
                    $refund     = $return_amt - $due_adjust;

                    if ($refund > 0) {
                        $obj = new \stdClass();
                        $obj->reference_no = $return->reference_no;
                        $obj->amount       = $refund;
                        $obj->created_at   = $return->created_at;
                        $obj->type         = 'debit';
                        return $obj;
                    }
                    return null;
                })->filter();

            $account_statement_array = $account_statement_array
                ->concat($purchase_payment)
                ->concat($expense)
                ->concat($payroll)
                ->concat($money_sent)
                ->concat($sales_returns);
        }

        // -----------------------------
        // Sort by created_at ASC
        // -----------------------------
        $account_statement_array = $account_statement_array->sortBy('created_at')->values();

        // -----------------------------
        // Build balance for each row
        // -----------------------------
        $balance_tracker = $balance;
        $final_array = [];
        foreach ($account_statement_array as $data) {
            $credit = $data->type == 'credit' ? $data->amount : 0;
            $debit  = $data->type == 'debit' ? $data->amount : 0;

            $balance_tracker += $credit;
            $balance_tracker -= $debit;

            $transaction_ref = '';
            if (isset($data->sale_id)) {
                $transaction_ref = Sale::where('id', $data->sale_id)->value('reference_no');
            } elseif (isset($data->purchase_id)) {
                $transaction_ref = Purchase::where('id', $data->purchase_id)->value('reference_no');
            }

            $final_array[] = [
                $data->created_at,
                $data->reference_no,
                $transaction_ref,
                $credit,
                $debit,
                $balance_tracker
            ];
        }
        return view('backend.account.account_statement', compact(
            'lims_account_data',
            'final_array',
            'balance_tracker',
            'initial_balance'
        ));
    }





    public function destroy($id)
    {
        if (!config('app.user_verified'))
            return redirect()->back()->with('not_permitted', __('db.This feature is disable for demo!'));
        $lims_account_data = Account::find($id);
        if (!$lims_account_data->is_default) {
            $lims_account_data->is_active = false;
            $lims_account_data->save();
            return redirect('accounts')->with('not_permitted', __('db.Account deleted successfully!'));
        } else
            return redirect('accounts')->with('not_permitted', __('db.Please make another account default first!'));
    }

    public function accountsAll()
    {
        $lims_account_list = DB::table('accounts')->where('is_active', true)->get();

        $html = '';
        foreach ($lims_account_list as $account) {
            if ($account->is_default == 1) {
                $html .= '<option selected value="' . $account->id . '">' . $account->name . ' (' . $account->account_no . ')' . '</option>';
            } else {
                $html .= '<option value="' . $account->id . '">' . $account->name . ' (' . $account->account_no . ')' . '</option>';
            }
        }

        return response()->json($html);
    }
}
