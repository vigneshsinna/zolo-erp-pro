<?php

namespace Tests\Support;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Commercial service fixtures owned by company A, branch MAIN, with a mapped chart of accounts. */
abstract class CompanyErpServiceTestCase extends CompanyContextTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::findOrFail(1));
        foreach (['customers', 'suppliers', 'billers'] as $tableName) {
            DB::table($tableName)->update(['company_id' => $this->company->id]);
        }
        DB::table('warehouses')->update(['company_id' => $this->company->id, 'branch_id' => $this->branch->id]);
        foreach (['units', 'accounts'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->unsignedBigInteger('company_id');
            });
            DB::table($tableName)->insert(['id' => 1, 'name' => 'Owned A', 'company_id' => $this->company->id]);
        }
        foreach ([
            ['1010', 'asset', 'cash'], ['1100', 'asset', 'accounts_receivable'],
            ['1200', 'asset', 'inventory'], ['2010', 'liability', 'accounts_payable'],
            ['4010', 'revenue', 'sales_revenue'], ['5010', 'expense', 'cogs'],
            ['GIT', 'asset', 'goods_in_transit'],
        ] as [$code, $type, $subType]) {
            (new ChartOfAccount)->forceFill([
                'company_id' => $this->company->id,
                'code' => $code, 'name' => $subType, 'type' => $type, 'sub_type' => $subType,
            ])->save();
        }
        $this->installAccountingFoundation();
        app(\App\Services\Accounting\SemanticAccountResolver::class)->seedCompany($this->company->id);
    }

    protected function stock(float $qty = 20, float $cost = 5): Product
    {
        $product = parent::stock($qty, $cost);
        $product->forceFill(['company_id' => $this->company->id])->save();
        Product_Warehouse::where('product_id', $product->id)->update(['company_id' => $this->company->id]);
        return $product;
    }
}
