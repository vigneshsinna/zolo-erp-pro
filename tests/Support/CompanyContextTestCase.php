<?php

namespace Tests\Support;

use App\Http\Middleware\ResolveCompanyContext;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\User;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

abstract class CompanyContextTestCase extends ErpServiceTestCase
{
    protected Company $company;
    protected Company $other;
    protected CompanyBranch $branch;
    protected CompanyBranch $otherBranch;
    protected FiscalYear $year;
    protected FiscalYear $otherYear;
    protected CompanyContextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2024_07_05_194501_add_is_packing_and_delivered_to_product_sales_table.php'))->up();
        (require database_path('migrations/2026_11_11_000001_add_optical_prescription_columns_to_product_sales_table.php'))->up();
        CarbonImmutable::setTestNow('2026-10-03 06:00:00 UTC');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('role_id')->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deleted')->default(false);
        });
        $viewCache = storage_path('framework/views');
        if (!is_dir($viewCache)) {
            mkdir($viewCache, 0777, true);
        }
        config(['view.compiled' => $viewCache]);
        config(['app.key' => 'base64:dGVzdC1vbmx5LWF1ZGl0LWZpeHR1cmUta2V5LTEyMzQ=']);
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('is_active')->default(true);
        });
        DB::table('roles')->insert([['id' => 1], ['id' => 2], ['id' => 4]]);
        DB::table('users')->insert([['id' => 1, 'name' => 'First operator'], ['id' => 2, 'name' => 'Other operator']]);
        (require database_path('migrations/2026_10_03_000001_create_company_context_tables.php'))->up();
        (require database_path('migrations/2026_10_03_000002_add_nullable_company_keys_to_core_tables.php'))->up();
        (require database_path('migrations/2026_10_03_000004_create_capability_tables.php'))->up();
        (require database_path('migrations/2026_10_03_000005_create_document_numbering_tables.php'))->up();

        $this->company = Company::create(['code' => 'A', 'legal_name' => 'Company A', 'timezone' => 'Asia/Kolkata']);
        $this->other = Company::create(['code' => 'B', 'legal_name' => 'Company B', 'timezone' => 'UTC']);
        $this->company->users()->attach(1, ['is_default' => true]);
        $this->other->users()->attach(2, ['is_default' => true]);
        $this->branch = $this->company->branches()->create(['code' => 'MAIN', 'name' => 'First branch']);
        $this->otherBranch = $this->other->branches()->create(['code' => 'MAIN', 'name' => 'Other branch']);
        DB::table('company_user_branches')->insert([
            ['company_id' => $this->company->id, 'user_id' => 1, 'branch_id' => $this->branch->id],
            ['company_id' => $this->other->id, 'user_id' => 2, 'branch_id' => $this->otherBranch->id],
        ]);
        $this->year = FiscalYear::create([
            'company_id' => $this->company->id, 'name' => '2026',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open',
        ]);
        $this->otherYear = FiscalYear::create([
            'company_id' => $this->other->id, 'name' => '2026',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open',
        ]);
        $this->resolver = new CompanyContextResolver();
        (require database_path('migrations/2026_10_03_000005_create_document_numbering_tables.php'))->up();
    }

    protected function installAccountingFoundation(): void
    {
        (require database_path('migrations/2026_09_19_000004_create_inventory_close_and_mappings_tables.php'))->up();
        if (!Schema::hasColumn('semantic_account_mappings', 'company_id')) {
            Schema::table('semantic_account_mappings', fn (Blueprint $table) => $table->unsignedBigInteger('company_id')->nullable());
        }
        if (!Schema::hasTable('accounts')) {
            Schema::create('accounts', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->unsignedBigInteger('company_id')->nullable();
            });
        }
        (require database_path('migrations/2026_10_04_000002_harden_accounting_and_create_open_items.php'))->up();
        // Cash/bank register metadata used when a COA ledger becomes a payment account.
        Schema::table('accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('accounts', 'account_no')) $table->string('account_no')->default('');
            foreach (['initial_balance', 'total_balance'] as $column) {
                if (!Schema::hasColumn('accounts', $column)) $table->decimal($column, 18, 4)->default(0);
            }
            foreach (['is_active' => true, 'is_default' => false] as $column => $default) {
                if (!Schema::hasColumn('accounts', $column)) $table->boolean($column)->default($default);
            }
            if (!Schema::hasColumn('accounts', 'created_at')) $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    protected function seedCompanyReaderFixtures(): void
    {
        foreach (['categories', 'brands', 'units', 'customer_groups'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->unsignedBigInteger('company_id')->nullable();
            });
            DB::table($tableName)->insert([
                ['id' => 1, 'name' => 'Visible A', 'company_id' => $this->company->id],
                ['id' => 2, 'name' => 'Secret B', 'company_id' => $this->other->id],
            ]);
        }
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->string('image')->nullable();
            foreach (['category_id', 'brand_id', 'unit_id'] as $column) {
                $table->unsignedInteger($column)->nullable();
            }
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->string('phone_number')->nullable();
            $table->string('email')->nullable();
            $table->unsignedInteger('customer_group_id')->nullable();
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->string('phone_number')->nullable();
            $table->string('company_name')->nullable();
        });
        foreach (['customers', 'suppliers'] as $tableName) {
            DB::table($tableName)->where('id', 1)->update(['name' => 'Visible A', 'company_id' => $this->company->id]);
            DB::table($tableName)->insert(['id' => 2, 'name' => 'Secret B', 'company_id' => $this->other->id]);
        }
        DB::table('customers')->where('id', 1)->update(['customer_group_id' => 1]);
        DB::table('customers')->where('id', 2)->update(['customer_group_id' => 2]);
        DB::table('warehouses')->where('id', 1)->update(['company_id' => $this->company->id, 'branch_id' => $this->branch->id]);
        DB::table('warehouses')->where('id', 2)->update(['company_id' => $this->other->id, 'branch_id' => $this->otherBranch->id]);
        $north = $this->company->branches()->create(['code' => 'NORTH', 'name' => 'Restricted branch']);
        DB::table('warehouses')->insert(['id' => 3, 'name' => 'Restricted stock', 'company_id' => $this->company->id, 'branch_id' => $north->id]);
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Visible A', 'code' => 'A', 'qty' => 99, 'cost' => 5, 'price' => 10, 'company_id' => $this->company->id, 'category_id' => 1, 'brand_id' => 1, 'unit_id' => 1],
            ['id' => 2, 'name' => 'Secret B', 'code' => 'B', 'qty' => 8, 'cost' => 7, 'price' => 15, 'company_id' => $this->other->id, 'category_id' => 2, 'brand_id' => 2, 'unit_id' => 2],
        ]);
        DB::table('product_warehouse')->insert([
            ['product_id' => '1', 'warehouse_id' => 1, 'qty' => 4, 'company_id' => $this->company->id],
            ['product_id' => '1', 'warehouse_id' => 3, 'qty' => 95, 'company_id' => $this->company->id],
            ['product_id' => '2', 'warehouse_id' => 2, 'qty' => 8, 'company_id' => $this->other->id],
        ]);
        \Laravel\Sanctum\Sanctum::actingAs(User::findOrFail(1));
    }

    protected function seedCompanyTransactionFixtures(): void
    {
        $this->seedCompanyReaderFixtures();
        DB::table('billers')->where('id', 1)->update(['company_id' => $this->company->id]);
        DB::table('billers')->insert(['id' => 2, 'name' => 'Secret B', 'company_id' => $this->other->id]);
        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedBigInteger('company_id')->nullable();
        });
        DB::table('accounts')->insert([
            ['id' => 1, 'name' => 'Cash A', 'company_id' => $this->company->id],
            ['id' => 2, 'name' => 'Secret B', 'company_id' => $this->other->id],
        ]);
        foreach ([1, 2, 3] as $id) {
            $owner = $id === 2 ? $this->other->id : $this->company->id;
            $party = $id === 2 ? 2 : 1;
            $header = [
                'id' => $id, 'reference_no' => 'DOC-'.$id, 'user_id' => 1,
                'company_id' => $owner, 'warehouse_id' => $id, 'item' => 1, 'total_qty' => 10,
                'total_discount' => 0, 'total_tax' => 0, 'grand_total' => 50,
                'payment_status' => 3, 'paid_amount' => 10, 'created_at' => '2026-10-01 10:00:00',
            ];
            DB::table('sales')->insert($header + [
                'customer_id' => $party, 'biller_id' => $party, 'total_price' => 50, 'sale_status' => 1,
            ]);
            DB::table('purchases')->insert($header + [
                'supplier_id' => $party, 'total_cost' => 50, 'status' => 2,
            ]);
            foreach (['product_sales', 'product_purchases'] as $tableName) {
                $line = [
                    'id' => $id, 'product_id' => $party, 'company_id' => $owner,
                    'qty' => 10, 'discount' => 0, 'tax_rate' => 0, 'tax' => 0, 'total' => 50,
                ];
                DB::table($tableName)->insert($line + ($tableName === 'product_sales'
                    ? ['sale_id' => $id, 'sale_unit_id' => $party, 'net_unit_price' => 5]
                    : ['purchase_id' => $id, 'purchase_unit_id' => $party, 'net_unit_cost' => 5, 'recieved' => 4]));
            }
        }
        foreach (['sale', 'purchase'] as $type) {
            DB::table('payments')->insert([
                'company_id' => $this->company->id, $type.'_id' => 1, 'user_id' => 1,
                'account_id' => 1, 'payment_reference' => 'PAY-'.$type,
                'amount' => 10, 'change' => 0, 'paying_method' => 'Cash',
            ]);
            // The foreign journal deliberately precedes the owned journal for the same source ID.
            foreach ([$this->other->id, $this->company->id] as $owner) {
                $accountId = DB::table('chart_of_accounts')->insertGetId([
                    'company_id' => $owner, 'code' => $type.'-'.$owner, 'name' => $owner === $this->other->id ? 'Secret B' : 'Inventory A',
                    'type' => 'asset', 'sub_type' => 'inventory',
                ]);
                $journalId = DB::table('journal_entries')->insertGetId([
                    'company_id' => $owner, 'entry_number' => $type.'-'.$owner,
                    'entry_date' => '2026-10-01', 'reference_type' => $type, 'reference_id' => 1,
                    'description' => $owner === $this->other->id ? 'Secret B' : 'Owned journal',
                    'total_debit' => 50, 'total_credit' => 50,
                ]);
                DB::table('journal_items')->insert([
                    'company_id' => $owner, 'journal_entry_id' => $journalId,
                    'chart_of_account_id' => $accountId, 'debit' => 50, 'credit' => 50,
                ]);
            }
        }
    }

}
