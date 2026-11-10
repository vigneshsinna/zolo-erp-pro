<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use \App\Models\Concerns\ScopesCompanyQueries;

    protected $fillable =[
        "company_id", "reference_no", "expense_category_id", "warehouse_id", "account_id",
        "user_id", "cash_register_id", "employee_id", "type",
        "amount", "note","document", "created_at"
    ];


    public function warehouse()
    {
    	return $this->belongsTo('App\Models\Warehouse');
    }

    public function expenseCategory() {
    	return $this->belongsTo('App\Models\ExpenseCategory');
    }

    public function employee()
    {
        return $this->belongsTo('App\Models\Employee');
    }

}
