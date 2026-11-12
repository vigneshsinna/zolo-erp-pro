<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Product_Warehouse;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportLenzbreezeDemoDataCommand extends Command
{
    protected $signature = 'erp:import-lenzbreeze-demo
                            {--company=6 : Target company ID}
                            {--warehouse=10 : Target warehouse ID}
                            {--products=100 : Total number of optical products to import}
                            {--customers=12 : Total number of optical customers to import}
                            {--sql=D:/PROJECTS/WEBSITES/lenzbreeze/database/u362580417_lenzbreezedb.sql : Path to LenzBreeze SQL dump}';

    protected $description = 'Import 100 authentic optical lens products and optical customers from LenzBreeze for demo';

    public function handle(): int
    {
        $companyId = (int) $this->option('company');
        $warehouseId = (int) $this->option('warehouse');
        $productTarget = (int) $this->option('products');
        $customerTarget = (int) $this->option('customers');
        $sqlPath = $this->option('sql');

        $company = Company::find($companyId);
        if (!$company) {
            $this->error("Company ID [{$companyId}] not found.");
            return self::FAILURE;
        }

        $warehouse = Warehouse::where('id', $warehouseId)->where('company_id', $companyId)->first()
            ?? Warehouse::where('company_id', $companyId)->first();

        if (!$warehouse) {
            $this->error("Warehouse ID [{$warehouseId}] not found for company [{$companyId}].");
            return self::FAILURE;
        }

        $this->info("=== Starting LenzBreeze Optical Demo Data Import ===");
        $this->info("Target Company: {$company->name} (ID: {$companyId})");
        $this->info("Target Warehouse: {$warehouse->name} (ID: {$warehouse->id})");

        // 1. Ensure Optical Units
        $prs = Unit::where('unit_code', 'PRS')->where('company_id', $companyId)->first()
            ?? Unit::where('unit_code', 'PRS')->first();
        if (!$prs) {
            $prs = Unit::create([
                'company_id' => $companyId,
                'unit_code' => 'PRS',
                'unit_name' => 'Pairs',
                'operator' => '*',
                'operation_value' => 1,
                'is_active' => true,
            ]);
        }

        // 2. Ensure Brands for Company
        $brandNames = ['LenzBreeze', 'Crizal', 'Essilor', 'Zeiss', 'Hoya', 'Nova'];
        $brandMap = [];
        foreach ($brandNames as $bName) {
            $b = Brand::withoutGlobalScope('request_company')
                ->where('company_id', $companyId)
                ->where('title', $bName)
                ->first();
            if (!$b) {
                $b = Brand::forceCreate([
                    'company_id' => $companyId,
                    'title' => $bName,
                    'is_active' => true,
                ]);
            }
            $brandMap[$bName] = $b;
        }
        $brand = $brandMap['LenzBreeze'];

        // 3. Ensure Categories for Company
        $categoryNames = [
            'Progressive Lenses (PAL)',
            'Bifocal & Kryptok',
            'Single Vision Lenses',
            'Finished Blanks (FSV)',
            'Semi-Finished Blanks (SF)',
            'Optical Accessories & Services',
        ];
        $catMap = [];
        foreach ($categoryNames as $cName) {
            $c = Category::withoutGlobalScope('request_company')
                ->where('company_id', $companyId)
                ->where('name', $cName)
                ->first();
            if (!$c) {
                $c = Category::forceCreate([
                    'company_id' => $companyId,
                    'name' => $cName,
                    'is_active' => true,
                ]);
            }
            $catMap[$cName] = $c;
        }
        $catProgressive = $catMap['Progressive Lenses (PAL)'];
        $catBifocal = $catMap['Bifocal & Kryptok'];
        $catSingleVision = $catMap['Single Vision Lenses'];

        // 4. Resolve Product Types
        $proType = ProductType::where('name', 'Progressive')->first()
            ?? ProductType::where('name', 'like', 'Progressive%')->first();
        $bifocalType = ProductType::where('name', 'D-Bifocal')->first()
            ?? ProductType::where('name', 'like', '%Bifocal%')->first();

        $proTypeId = $proType ? $proType->id : null;
        $bifocalTypeId = $bifocalType ? $bifocalType->id : null;

        // 5. Stream SQL dump and extract products
        if (!file_exists($sqlPath)) {
            $this->error("LenzBreeze SQL dump not found at: {$sqlPath}");
            return self::FAILURE;
        }

        $fp = fopen($sqlPath, 'r');
        if (!$fp) {
            $this->error("Could not open SQL file: {$sqlPath}");
            return self::FAILURE;
        }

        $halfTarget = (int) ceil($productTarget / 2);
        $proProducts = [];
        $bifocalProducts = [];
        $readingProducts = false;

        $this->info("Scanning SQL file for optical lens records (50 PAL + 50 Bifocal)...");

        while (($line = fgets($fp)) !== false) {
            if (strpos($line, "INSERT INTO `products`") !== false) {
                $readingProducts = true;
                continue;
            }

            if ($readingProducts) {
                // Progressive Lenses (LB-...)
                if (strpos($line, "'LB-") !== false && count($proProducts) < $halfTarget) {
                    if (preg_match("/^\((\d+),\s*'([^']*)',\s*'([^']*)',\s*(?:'[^']*'|NULL),\s*(?:'([^']*)'|NULL),\s*'([^']*)',\s*(\d+),\s*'([^']*)',\s*(\d+|NULL),\s*(\d+),\s*(\d+),\s*(\d+),\s*(\d+),\s*([0-9.]+),\s*([0-9.]+)/", $line, $m)) {
                        $base = '2.00'; $add = '+1.00'; $lr = 'R';
                        if (preg_match("/,\s*(?:'([^']*)'|NULL),\s*(?:'([^']*)'|NULL),\s*(?:'([^']*)'|NULL)\)[,;]?$/", trim($line), $endM)) {
                            $base = $endM[1] ?? '2.00';
                            $add = $endM[2] ?? '+1.00';
                            $lr = $endM[3] ?? 'R';
                        }
                        $proProducts[] = [
                            'name' => $m[2],
                            'code' => $m[3],
                            'base' => $base,
                            'addition' => $add,
                            'lr' => $lr,
                            'dia' => '70',
                            'cost' => (float)$m[13] > 0 ? (float)$m[13] : 100.00,
                            'price' => (float)$m[14] > 0 ? (float)$m[14] : 225.00,
                            'category_id' => $catProgressive->id,
                            'product_type_id' => $proTypeId,
                        ];
                    }
                }

                // Bifocal & Kryptok (LBS-...)
                if (strpos($line, "'LBS-") !== false && count($bifocalProducts) < $halfTarget) {
                    if (preg_match("/^\((\d+),\s*'([^']*)',\s*'([^']*)',\s*(?:'[^']*'|NULL),\s*(?:'([^']*)'|NULL),\s*'([^']*)',\s*(\d+),\s*'([^']*)',\s*(\d+|NULL),\s*(\d+),\s*(\d+),\s*(\d+),\s*(\d+),\s*([0-9.]+),\s*([0-9.]+)/", $line, $m)) {
                        $base = 'PLANO'; $add = '+1.00'; $lr = 'R';
                        if (preg_match("/,\s*(?:'([^']*)'|NULL),\s*(?:'([^']*)'|NULL),\s*(?:'([^']*)'|NULL)\)[,;]?$/", trim($line), $endM)) {
                            $base = $endM[1] ?? 'PLANO';
                            $add = $endM[2] ?? '+1.00';
                            $lr = $endM[3] ?? 'R';
                        }
                        $bifocalProducts[] = [
                            'name' => $m[2],
                            'code' => $m[3],
                            'base' => $base,
                            'addition' => $add,
                            'lr' => $lr,
                            'dia' => '70',
                            'cost' => (float)$m[13] > 0 ? (float)$m[13] : 81.00,
                            'price' => (float)$m[14] > 0 ? (float)$m[14] : 180.00,
                            'category_id' => $catBifocal->id,
                            'product_type_id' => $bifocalTypeId,
                        ];
                    }
                }

                if (count($proProducts) >= $halfTarget && count($bifocalProducts) >= $halfTarget) {
                    break;
                }
                if (substr(trim($line), -1) === ';') {
                    $readingProducts = false;
                }
            }
        }

        // 6. Reset pointer and stream optical customers
        rewind($fp);
        $customers = [];
        $readingCustomers = false;

        while (($line = fgets($fp)) !== false) {
            if (strpos($line, "INSERT INTO `customers`") !== false) {
                $readingCustomers = true;
                continue;
            }

            if ($readingCustomers) {
                if (preg_match("/^\((\d+),\s*(\d+|NULL),\s*(\d+|NULL),\s*'([^']*)',\s*(?:'([^']*)'|NULL),\s*(?:'([^']*)'|NULL),\s*(?:'([^']*)'|NULL),\s*(?:'([^']*)'|NULL),\s*(?:'([^']*)'|NULL),\s*(?:'([^']*)'|NULL)/", $line, $m)) {
                    $cName = trim($m[4]);
                    $cPhone = trim($m[7] ?? '');
                    $cTax = trim($m[8] ?? '');
                    $cAddress = trim($m[9] ?? '');
                    $cCity = trim($m[10] ?? 'Thiruvananthapuram');

                    if (
                        strpos(strtoupper($cName), 'TEST') === false &&
                        strpos(strtoupper($cName), 'DISCOUNT') === false &&
                        strlen($cName) >= 4
                    ) {
                        $customers[] = [
                            'name' => $cName,
                            'phone' => $cPhone ?: '9876543210',
                            'tax_no' => $cTax ?: null,
                            'address' => $cAddress ?: 'Commercial Optical Street',
                            'city' => $cCity ?: 'Thiruvananthapuram',
                        ];
                    }
                }

                if (count($customers) >= $customerTarget) {
                    break;
                }
                if (substr(trim($line), -1) === ';') {
                    $readingCustomers = false;
                }
            }
        }
        fclose($fp);

        // Fallback curated optical customers if dump had fewer than target
        if (count($customers) < $customerTarget) {
            $fallbackCusts = [
                ['name' => 'ARPANA OPTICALS', 'phone' => '9447863297', 'tax_no' => null, 'address' => 'Near Aaradhana Eye Hospital, Manacaud', 'city' => 'Thiruvananthapuram'],
                ['name' => '4 EYES OPTICALS', 'phone' => '9809171229', 'tax_no' => null, 'address' => 'Mannamthala', 'city' => 'Thiruvananthapuram'],
                ['name' => 'ADITYA OPTICALS', 'phone' => '7736061887', 'tax_no' => '32BKKPS8793K1Z9', 'address' => 'TRA.C-2, Tutors Lane, Staute', 'city' => 'Thiruvananthapuram'],
                ['name' => 'ALEENA OPTICALS', 'phone' => '9747366788', 'tax_no' => '32AAJPF2577Q1ZB', 'address' => 'Near General Hospital Road', 'city' => 'Thiruvananthapuram'],
                ['name' => 'NOEL OPTICALS', 'phone' => '9446701206', 'tax_no' => null, 'address' => 'Dhanuvachapuram', 'city' => 'Thiruvananthapuram'],
                ['name' => 'EVEREST OPTICALS', 'phone' => '8113900390', 'tax_no' => null, 'address' => 'Kunnathukal', 'city' => 'Thiruvananthapuram'],
                ['name' => 'SPECS CLUB OPTICALS', 'phone' => '9072589555', 'tax_no' => null, 'address' => 'Palarivattom', 'city' => 'Ernakulam'],
                ['name' => 'EYE CARE OPTICALS', 'phone' => '9947273010', 'tax_no' => null, 'address' => 'T D Road', 'city' => 'Ernakulam'],
                ['name' => 'INDIAN OPTICALS', 'phone' => '9895148362', 'tax_no' => null, 'address' => 'M G Road', 'city' => 'Ernakulam'],
                ['name' => 'GALAXY OPTICALS', 'phone' => '9544879663', 'tax_no' => null, 'address' => 'Ambalamukku', 'city' => 'Thiruvananthapuram'],
                ['name' => 'LENS WORLD', 'phone' => '7907843898', 'tax_no' => null, 'address' => 'Ambalamukku', 'city' => 'Thiruvananthapuram'],
                ['name' => 'LAWRENCE & MOYO OPTICALS', 'phone' => '04842368645', 'tax_no' => null, 'address' => 'Iyyathil Jn., Chittoor Road', 'city' => 'Ernakulam'],
            ];
            foreach ($fallbackCusts as $fc) {
                if (count($customers) >= $customerTarget) break;
                $customers[] = $fc;
            }
        }

        // 7. Persist Products and Initial Warehouse Stock
        $allProducts = array_merge($proProducts, $bifocalProducts);
        $this->info("Inserting " . count($allProducts) . " optical lens products...");

        $productsCreated = 0;
        $stockCreated = 0;

        DB::transaction(function () use ($allProducts, $companyId, $warehouse, $brand, $prs, &$productsCreated, &$stockCreated) {
            foreach ($allProducts as $pData) {
                $product = Product::withoutGlobalScope('request_company')
                    ->where('company_id', $companyId)
                    ->where('code', $pData['code'])
                    ->first();

                if (!$product) {
                    $product = Product::forceCreate([
                        'company_id' => $companyId,
                        'name' => $pData['name'],
                        'print_name' => $pData['name'],
                        'code' => $pData['code'],
                        'type' => 'standard',
                        'product_type_id' => $pData['product_type_id'],
                        'barcode_symbology' => 'code128',
                        'brand_id' => $brand->id,
                        'category_id' => $pData['category_id'],
                        'unit_id' => $prs->id,
                        'purchase_unit_id' => $prs->id,
                        'sale_unit_id' => $prs->id,
                        'cost' => $pData['cost'],
                        'price' => $pData['price'],
                        'qty' => 50,
                        'alert_quantity' => 5,
                        'base' => $pData['base'],
                        'addition' => $pData['addition'],
                        'lr' => $pData['lr'],
                        'dia' => $pData['dia'],
                        'hsn_code' => '9001',
                        'tax_method' => 1,
                        'is_active' => true,
                    ]);
                    $productsCreated++;
                } else {
                    $product->update([
                        'brand_id' => $brand->id,
                        'category_id' => $pData['category_id'],
                        'product_type_id' => $pData['product_type_id'],
                    ]);
                }

                // Warehouse stock
                $pw = Product_Warehouse::where('product_id', $product->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->first();

                if (!$pw) {
                    Product_Warehouse::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'qty' => 50,
                        'price' => $product->price,
                    ]);
                    $stockCreated++;
                }
            }
        });

        $this->info("Products successfully inserted: {$productsCreated}");
        $this->info("Warehouse stock rows inserted: {$stockCreated} (50 pairs each in {$warehouse->name})");

        // 8. Persist Optical Customers
        $customerGroup = CustomerGroup::where('company_id', $companyId)->first()
            ?? CustomerGroup::first();

        $this->info("Inserting " . count($customers) . " optical customers...");
        $customersCreated = 0;

        foreach ($customers as $cData) {
            $cust = Customer::withoutGlobalScope('request_company')
                ->where('company_id', $companyId)
                ->where('name', $cData['name'])
                ->first();

            if (!$cust) {
                Customer::create([
                    'company_id' => $companyId,
                    'customer_group_id' => $customerGroup ? $customerGroup->id : 1,
                    'name' => $cData['name'],
                    'company_name' => $cData['name'],
                    'phone_number' => $cData['phone'],
                    'tax_no' => $cData['tax_no'],
                    'address' => $cData['address'],
                    'city' => $cData['city'],
                    'state' => 'Kerala',
                    'country' => 'India',
                    'postal_code' => '695001',
                    'is_active' => true,
                ]);
                $customersCreated++;
            }
        }

        $this->info("Customers successfully created: {$customersCreated}");
        $this->info("=== LenzBreeze Optical Demo Data Import Completed Successfully ===");

        return self::SUCCESS;
    }
}

