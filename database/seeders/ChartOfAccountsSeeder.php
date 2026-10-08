<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\FiscalYear;
use Carbon\Carbon;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Initial Fiscal Year
        $currentYear = Carbon::now()->year;
        FiscalYear::firstOrCreate(
            ['name' => "FY-$currentYear"],
            [
                'start_date' => Carbon::now()->startOfYear()->toDateString(),
                'end_date' => Carbon::now()->endOfYear()->toDateString(),
                'is_closed' => false,
            ]
        );

        // 2. Standard Chart of Accounts
        $accounts = self::accounts();

        // Seed parents first, then children
        foreach ($accounts as $data) {
            $parentId = null;
            if (!empty($data['parent_code'])) {
                $parent = ChartOfAccount::where('code', $data['parent_code'])->first();
                $parentId = $parent?->id;
            }

            ChartOfAccount::updateOrCreate(
                ['code' => $data['code']],
                [
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'sub_type' => $data['sub_type'],
                    'parent_id' => $parentId,
                    'is_system' => $data['is_system'],
                    'description' => $data['description'],
                    'is_active' => true,
                ]
            );
        }
    }

    /** Standard chart template; also installed for every company created from the company picker. */
    public static function accounts(): array
    {
        return [
            // ASSETS (1000 - 1999)
            [
                'code' => '1000',
                'name' => 'Current Assets',
                'type' => 'asset',
                'sub_type' => 'current_asset',
                'parent_code' => null,
                'is_system' => false,
                'description' => 'Assets expected to be converted to cash within one year',
            ],
            [
                'code' => '1010',
                'name' => 'Cash on Hand / Cash Register',
                'type' => 'asset',
                'sub_type' => 'cash',
                'parent_code' => '1000',
                'is_system' => true,
                'description' => 'Physical currency and till float',
            ],
            [
                'code' => '1020',
                'name' => 'Bank Accounts',
                'type' => 'asset',
                'sub_type' => 'bank',
                'parent_code' => '1000',
                'is_system' => true,
                'description' => 'Funds held in primary and secondary bank accounts',
            ],
            [
                'code' => '1100',
                'name' => 'Accounts Receivable (Debtors)',
                'type' => 'asset',
                'sub_type' => 'accounts_receivable',
                'parent_code' => '1000',
                'is_system' => true,
                'description' => 'Amounts owed by customers for credit sales',
            ],
            [
                'code' => '1200',
                'name' => 'Merchandise Inventory Asset',
                'type' => 'asset',
                'sub_type' => 'inventory',
                'parent_code' => '1000',
                'is_system' => true,
                'description' => 'Total value of sellable stock on hand',
            ],
            [
                'code' => '1500',
                'name' => 'Fixed Assets',
                'type' => 'asset',
                'sub_type' => 'fixed_asset',
                'parent_code' => null,
                'is_system' => false,
                'description' => 'Long term physical equipment and premises',
            ],
            [
                'code' => '1510',
                'name' => 'Store & Office Equipment',
                'type' => 'asset',
                'sub_type' => 'fixed_asset',
                'parent_code' => '1500',
                'is_system' => false,
                'description' => 'POS terminals, computers, furniture',
            ],

            // LIABILITIES (2000 - 2999)
            [
                'code' => '2000',
                'name' => 'Current Liabilities',
                'type' => 'liability',
                'sub_type' => 'current_liability',
                'parent_code' => null,
                'is_system' => false,
                'description' => 'Debts and obligations due within one year',
            ],
            [
                'code' => '2010',
                'name' => 'Accounts Payable (Creditors)',
                'type' => 'liability',
                'sub_type' => 'accounts_payable',
                'parent_code' => '2000',
                'is_system' => true,
                'description' => 'Amounts owed to suppliers for inventory and services',
            ],
            [
                'code' => '2020',
                'name' => 'Sales Tax / VAT Payable',
                'type' => 'liability',
                'sub_type' => 'tax_payable',
                'parent_code' => '2000',
                'is_system' => true,
                'description' => 'Tax collected from customers payable to government',
            ],
            [
                'code' => '2030',
                'name' => 'Payroll & Wages Payable',
                'type' => 'liability',
                'sub_type' => 'current_liability',
                'parent_code' => '2000',
                'is_system' => false,
                'description' => 'Employee salaries accrued and pending payment',
            ],
            [
                'code' => '2040',
                'name' => 'Customer Advance Deposits',
                'type' => 'liability',
                'sub_type' => 'current_liability',
                'parent_code' => '2000',
                'is_system' => false,
                'description' => 'Unearned revenue / advance payments from customers',
            ],

            // EQUITY (3000 - 3999)
            [
                'code' => '3000',
                'name' => 'Equity',
                'type' => 'equity',
                'sub_type' => 'equity',
                'parent_code' => null,
                'is_system' => false,
                'description' => 'Residual interest in the assets after deducting liabilities',
            ],
            [
                'code' => '3010',
                'name' => "Owner's Capital / Share Capital",
                'type' => 'equity',
                'sub_type' => 'equity',
                'parent_code' => '3000',
                'is_system' => true,
                'description' => 'Initial and ongoing invested capital by owners',
            ],
            [
                'code' => '3020',
                'name' => 'Retained Earnings',
                'type' => 'equity',
                'sub_type' => 'equity',
                'parent_code' => '3000',
                'is_system' => true,
                'description' => 'Cumulative net income retained in business',
            ],
            [
                'code' => '3030',
                'name' => 'Owner Drawings',
                'type' => 'equity',
                'sub_type' => 'equity',
                'parent_code' => '3000',
                'is_system' => false,
                'description' => 'Withdrawals made by owners',
            ],

            // REVENUE (4000 - 4999)
            [
                'code' => '4000',
                'name' => 'Revenue',
                'type' => 'revenue',
                'sub_type' => 'sales_revenue',
                'parent_code' => null,
                'is_system' => false,
                'description' => 'Income generated from core operations',
            ],
            [
                'code' => '4010',
                'name' => 'Sales Revenue',
                'type' => 'revenue',
                'sub_type' => 'sales_revenue',
                'parent_code' => '4000',
                'is_system' => true,
                'description' => 'Gross income earned from product sales',
            ],
            [
                'code' => '4020',
                'name' => 'Sales Discounts & Rebates',
                'type' => 'revenue',
                'sub_type' => 'sales_discount',
                'parent_code' => '4000',
                'is_system' => false,
                'description' => 'Discounts granted to customers (Contra-revenue)',
            ],
            [
                'code' => '4030',
                'name' => 'Shipping & Delivery Fees Collected',
                'type' => 'revenue',
                'sub_type' => 'other_income',
                'parent_code' => '4000',
                'is_system' => false,
                'description' => 'Freight and shipping charges billed to customers',
            ],
            [
                'code' => '4090',
                'name' => 'Other Income',
                'type' => 'revenue',
                'sub_type' => 'other_income',
                'parent_code' => '4000',
                'is_system' => false,
                'description' => 'Secondary income streams (interest, scrap sales)',
            ],

            // EXPENSES (5000 - 6999)
            [
                'code' => '5000',
                'name' => 'Cost of Goods Sold (COGS)',
                'type' => 'expense',
                'sub_type' => 'cogs',
                'parent_code' => null,
                'is_system' => false,
                'description' => 'Direct cost of merchandise sold',
            ],
            [
                'code' => '5010',
                'name' => 'Cost of Goods Sold - Products',
                'type' => 'expense',
                'sub_type' => 'cogs',
                'parent_code' => '5000',
                'is_system' => true,
                'description' => 'Cost allocated when products are sold',
            ],
            [
                'code' => '5020',
                'name' => 'Inventory Shrinkage & Write-offs',
                'type' => 'expense',
                'sub_type' => 'cogs',
                'parent_code' => '5000',
                'is_system' => false,
                'description' => 'Losses due to damage, expiry, or discrepancies',
            ],
            [
                'code' => '6000',
                'name' => 'Operating Expenses',
                'type' => 'expense',
                'sub_type' => 'operating_expense',
                'parent_code' => null,
                'is_system' => false,
                'description' => 'General, administrative, and selling overheads',
            ],
            [
                'code' => '6010',
                'name' => 'Salaries, Wages & Commissions',
                'type' => 'expense',
                'sub_type' => 'operating_expense',
                'parent_code' => '6000',
                'is_system' => false,
                'description' => 'Payroll expense for staff and sales team',
            ],
            [
                'code' => '6020',
                'name' => 'Rent & Facility Expense',
                'type' => 'expense',
                'sub_type' => 'operating_expense',
                'parent_code' => '6000',
                'is_system' => false,
                'description' => 'Shop, office, and warehouse lease payments',
            ],
            [
                'code' => '6030',
                'name' => 'Utilities & Communication',
                'type' => 'expense',
                'sub_type' => 'operating_expense',
                'parent_code' => '6000',
                'is_system' => false,
                'description' => 'Electricity, water, telephone, and internet services',
            ],
            [
                'code' => '6040',
                'name' => 'Marketing & Promotional Expense',
                'type' => 'expense',
                'sub_type' => 'operating_expense',
                'parent_code' => '6000',
                'is_system' => false,
                'description' => 'Advertising, social media ads, flyers',
            ],
            [
                'code' => '6050',
                'name' => 'Courier & Freight Outward',
                'type' => 'expense',
                'sub_type' => 'operating_expense',
                'parent_code' => '6000',
                'is_system' => false,
                'description' => 'Shipping costs incurred delivering orders to customers',
            ],
            [
                'code' => '6060',
                'name' => 'Bank & Payment Gateway Charges',
                'type' => 'expense',
                'sub_type' => 'operating_expense',
                'parent_code' => '6000',
                'is_system' => false,
                'description' => 'Card processing fees (Stripe, Paypal, POS swipe)',
            ],
            [
                'code' => '6090',
                'name' => 'General & Miscellaneous Expense',
                'type' => 'expense',
                'sub_type' => 'operating_expense',
                'parent_code' => '6000',
                'is_system' => false,
                'description' => 'Sundry expenses not categorized elsewhere',
            ],
        ];
    }
}
