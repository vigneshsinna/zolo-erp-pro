<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\SaleType;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OpticalsMasterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $companies = Company::all();
        if ($companies->isEmpty()) {
            $this->seedCompany(null);
            return;
        }

        foreach ($companies as $company) {
            $this->seedCompany($company);
        }
    }

    /**
     * Seed optical units, brands, categories, sale types, products and series for a company.
     */
    public function seedCompany(?Company $company, bool $catalogOnly = false): void
    {
        $companyId = $company ? $company->id : 1;

        // 1. Ensure Optical Units (PRS, PCS, BOX)
        $units = $this->ensureUnits($companyId);

        // 2. Ensure Optical Brands
        $brands = $this->ensureBrands($companyId);

        // 3. Ensure Optical Categories
        $categories = $this->ensureCategories($companyId);

        // 3b. Ensure Optical Product Types (LenzBreeze Lens Types)
        (new ProductTypeSeeder())->run($companyId);

        // 4. Ensure Optical Sale Types (HSN 9001 @ 12% GST)
        $this->ensureSaleTypes($companyId);

        // 5. Ensure Document Series (LAB-, PS-, INV-)
        if ($company) {
            $this->ensureDocumentSeries($company);
        }

        // 6. Seed Optical Products
        $this->seedProducts($companyId, $units, $brands, $categories, $catalogOnly);
    }

    /**
     * Ensure optical units (PRS, PCS, BOX) exist.
     */
    private function ensureUnits(int $companyId): array
    {
        $unitMap = [];

        // Pairs (PRS) - Standard unit for finished and Rx lenses
        $prs = Unit::where('unit_code', 'PRS')->where('company_id', $companyId)->first()
            ?? Unit::where('unit_code', 'PR')->where('company_id', $companyId)->first()
            ?? Unit::where('unit_code', 'PRS')->first();

        if (!$prs) {
            $prs = Unit::create([
                'company_id' => $companyId,
                'unit_code' => 'PRS',
                'unit_name' => 'Pairs',
                'base_unit' => null,
                'operator' => '*',
                'operation_value' => 1,
                'is_active' => true,
            ]);
        }
        $unitMap['PRS'] = $prs->id;

        // Pieces (PCS)
        $pcs = Unit::where('unit_code', 'PCS')->where('company_id', $companyId)->first()
            ?? Unit::where('unit_code', 'Pc')->where('company_id', $companyId)->first()
            ?? Unit::where('unit_code', 'PCS')->first();

        if (!$pcs) {
            $pcs = Unit::create([
                'company_id' => $companyId,
                'unit_code' => 'PCS',
                'unit_name' => 'Pieces',
                'base_unit' => null,
                'operator' => '*',
                'operation_value' => 1,
                'is_active' => true,
            ]);
        }
        $unitMap['PCS'] = $pcs->id;

        // Box (BOX)
        $box = Unit::where('unit_code', 'BOX')->where('company_id', $companyId)->first()
            ?? Unit::where('unit_code', 'BOX')->first();

        if (!$box) {
            $box = Unit::create([
                'company_id' => $companyId,
                'unit_code' => 'BOX',
                'unit_name' => 'Box',
                'base_unit' => $prs->id,
                'operator' => '*',
                'operation_value' => 10,
                'is_active' => true,
            ]);
        }
        $unitMap['BOX'] = $box->id;

        return $unitMap;
    }

    /**
     * Ensure optical lens brands.
     */
    private function ensureBrands(int $companyId): array
    {
        $brandMap = [];
        $brandNames = ['LenzBreeze', 'Crizal', 'Essilor', 'Zeiss', 'Hoya', 'Nova'];

        foreach ($brandNames as $title) {
            $brand = Brand::where('title', $title)->where('company_id', $companyId)->first();
            if (!$brand) {
                $brand = Brand::create([
                    'company_id' => $companyId,
                    'title' => $title,
                    'is_active' => true,
                ]);
            }
            $brandMap[$title] = $brand->id;
        }

        return $brandMap;
    }

    /**
     * Ensure optical categories.
     */
    private function ensureCategories(int $companyId): array
    {
        $catMap = [];
        $categories = [
            'Single Vision Lenses' => ['code' => 'CAT-SV', 'has_warranty' => 1],
            'Bifocal & Kryptok' => ['code' => 'CAT-BF', 'has_warranty' => 0],
            'Progressive Lenses (PAL)' => ['code' => 'CAT-PRO', 'has_warranty' => 1],
            'Finished Blanks (FSV)' => ['code' => 'CAT-FSV', 'has_warranty' => 0],
            'Semi-Finished Blanks (SF)' => ['code' => 'CAT-SF', 'has_warranty' => 0],
            'Optical Accessories & Services' => ['code' => 'CAT-ACC', 'has_warranty' => 0],
        ];

        foreach ($categories as $name => $meta) {
            $cat = Category::where('name', $name)->where('company_id', $companyId)->first();
            if (!$cat) {
                $catData = [
                    'company_id' => $companyId,
                    'name' => $name,
                    'is_active' => true,
                ];
                if (Schema::hasColumn('categories', 'code')) {
                    $catData['code'] = $meta['code'];
                }
                if (Schema::hasColumn('categories', 'has_warranty')) {
                    $catData['has_warranty'] = $meta['has_warranty'];
                }
                $cat = Category::create($catData);
            }
            $catMap[$name] = $cat->id;
        }

        return $catMap;
    }

    /**
     * Ensure optical sale types (including HSN 9001 12% GST).
     */
    private function ensureSaleTypes(int $companyId): void
    {
        if (!Schema::hasTable('sale_types')) return;

        $saleTypes = [
            ['name' => '12%-GST (Optical Lenses)', 'code' => 'SGST12-OPT', 'tax_nature' => 'local', 'tax_rate' => 12],
            ['name' => '12%-IGST (Optical Lenses)', 'code' => 'SIGST12-OPT', 'tax_nature' => 'interstate', 'tax_rate' => 12],
            ['name' => '18%-GST (Frames & Fitting)', 'code' => 'SGST18-OPT', 'tax_nature' => 'local', 'tax_rate' => 18],
            ['name' => '18%-IGST (Frames & Fitting)', 'code' => 'SIGST18-OPT', 'tax_nature' => 'interstate', 'tax_rate' => 18],
            ['name' => 'L/MultiTax (Opticals)', 'code' => 'SMULTI-OPT', 'tax_nature' => 'local', 'tax_rate' => 0],
            ['name' => 'Interstate MultiTax (Opticals)', 'code' => 'SIMULTI-OPT', 'tax_nature' => 'interstate', 'tax_rate' => 0],
        ];

        foreach ($saleTypes as $st) {
            SaleType::firstOrCreate(
                ['company_id' => $companyId, 'name' => $st['name']],
                $st + ['is_active' => true]
            );
        }
    }

    /**
     * Ensure LAB, PS, and INV document series for optical workflows.
     */
    private function ensureDocumentSeries(Company $company): void
    {
        if (!Schema::hasTable('document_series')) return;

        $branch = DB::table('company_branches')->where('company_id', $company->id)->orderBy('id')->first();
        $fy = DB::table('fiscal_years')->where('company_id', $company->id)->orderBy('start_date')->first();
        if (!$branch || !$fy) return;

        $scope = ['company_id' => $company->id, 'branch_id' => $branch->id, 'financial_year_id' => $fy->id];
        $series = [
            ['lab_receipt', 'LAB RECEIPT', 'LAB-', 1],
            ['packing_slip', 'PACKING SLIP', 'PS-', 1],
            ['sale', 'OPTICAL SALE', 'INV-', 0],
        ];

        foreach ($series as [$type, $code, $prefix, $default]) {
            if (DB::table('document_series')->where($scope)->where('code', $code)->exists()) continue;
            $isDefault = $default && !DB::table('document_series')->where($scope)->where('document_type', $type)->where('is_default', true)->exists();
            DB::table('document_series')->insert($scope + [
                'document_type' => $type,
                'code' => $code,
                'prefix' => $prefix,
                'suffix' => '',
                'next_number' => 1,
                'padding' => 5,
                'is_default' => $isDefault,
                'reset_policy' => 'financial_year',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Seed optical products.
     */
    private function seedProducts(int $companyId, array $units, array $brands, array $categories, bool $catalogOnly = false): array
    {
        $products = [
            [
                'name' => 'LenzBreeze SV 1.56 BlueCut HMC',
                'code' => 'LB-SV-156-BC',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'brand_id' => $brands['LenzBreeze'] ?? null,
                'category_id' => $categories['Single Vision Lenses'] ?? null,
                'unit_id' => $units['PRS'],
                'purchase_unit_id' => $units['PRS'],
                'sale_unit_id' => $units['PRS'],
                'cost' => 600.00,
                'price' => 1200.00,
                'tax_id' => null,
                'tax_method' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'LenzBreeze Progressive FastTrack 1.56',
                'code' => 'LB-PRO-156-FT',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'brand_id' => $brands['LenzBreeze'] ?? null,
                'category_id' => $categories['Progressive Lenses (PAL)'] ?? null,
                'unit_id' => $units['PRS'],
                'purchase_unit_id' => $units['PRS'],
                'sale_unit_id' => $units['PRS'],
                'cost' => 1800.00,
                'price' => 3800.00,
                'tax_id' => null,
                'tax_method' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'LenzBreeze Bifocal Kryptok 1.50 UC',
                'code' => 'LB-BF-150-UC',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'brand_id' => $brands['LenzBreeze'] ?? null,
                'category_id' => $categories['Bifocal & Kryptok'] ?? null,
                'unit_id' => $units['PRS'],
                'purchase_unit_id' => $units['PRS'],
                'sale_unit_id' => $units['PRS'],
                'cost' => 400.00,
                'price' => 850.00,
                'tax_id' => null,
                'tax_method' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'Hi-Index 1.67 Aspheric Ultra Clear',
                'code' => 'LB-HI-167-UC',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'brand_id' => $brands['LenzBreeze'] ?? null,
                'category_id' => $categories['Finished Blanks (FSV)'] ?? null,
                'unit_id' => $units['PRS'],
                'purchase_unit_id' => $units['PRS'],
                'sale_unit_id' => $units['PRS'],
                'cost' => 2200.00,
                'price' => 4500.00,
                'tax_id' => null,
                'tax_method' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'Optical Surfacing, Edging & Fitting Service',
                'code' => 'LB-SRV-FIT',
                'type' => 'service',
                'barcode_symbology' => 'code128',
                'brand_id' => null,
                'category_id' => $categories['Optical Accessories & Services'] ?? null,
                'unit_id' => $units['PCS'],
                'purchase_unit_id' => $units['PCS'],
                'sale_unit_id' => $units['PCS'],
                'cost' => 100.00,
                'price' => 250.00,
                'tax_id' => null,
                'tax_method' => 1,
                'is_active' => true,
            ],
        ];

        $warehouse = Warehouse::where('company_id', $companyId)->first();
        $created = [];

        foreach ($products as $p) {
            $product = Product::withoutGlobalScope('request_company')->where('code', $p['code'])->where('company_id', $companyId)->first();
            if (!$product) {
                $product = Product::forceCreate($p + ['company_id' => $companyId]);
            }
            $created[] = $product;

            // Seed initial stock in main warehouse if not catalogOnly
            if (!$catalogOnly && $warehouse && $product->type === 'standard') {
                Product_Warehouse::firstOrCreate(
                    [
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                    ],
                    [
                        'qty' => 50,
                        'price' => $product->price,
                    ]
                );
            }
        }

        return $created;
    }
}

