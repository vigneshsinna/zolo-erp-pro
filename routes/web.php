<?php
require __DIR__.'/operations.php';

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\WebviewAuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\BillSundryController;
use App\Http\Controllers\SaleTypeController;
use App\Http\Controllers\PurchaseTypeController;
use App\Http\Controllers\StandardRemarkController;
use App\Http\Controllers\DocumentSeriesController;
use App\Http\Controllers\DeliveryChallanController;
use App\Http\Controllers\GoodsReceivedNoteController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\BillerController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\LabelsController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\BarcodeController;
use App\Http\Controllers\ChallanController;
use App\Http\Controllers\CourierController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PrinterController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\AccountsController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\GiftCardController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\RazorpayController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\PackingSlipController;
use App\Http\Controllers\SmsTemplateController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\AddonInstallController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\DiscountPlanController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CustomerGroupController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\MoneyTransferController;
use App\Http\Controllers\IncomeCategoryController;
use App\Http\Controllers\InvoiceSettingController;
use App\Http\Controllers\ReturnPurchaseController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\HrmController;
use App\Http\Controllers\InstallmentPlanController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\SaleAgentController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\WhatsappController;

require __DIR__.'/commercial.php';
require __DIR__.'/compliance.php';

Route::get('webview/auth', WebviewAuthController::class)->name('webview.auth');

// SECURITY: Route /migrate DIHAPUS - gunakan 'php artisan migrate' via terminal
// Route::get('migrate', function() { ... }); // DISABLED FOR SECURITY

// The web group enforces CSRF; the existing permission gate grants Admin access.
Route::post('clear', [MaintenanceController::class, 'clear'])->middleware(['auth', 'can:cache-clear'])->name('cache.clear');

// SECURITY: Installer routes otomatis dinonaktifkan jika sudah terinstall
// SECURITY: Cek status instalasi
$isInstalled = false;

// 1. Cek apakah file .env ada
if (file_exists(base_path('.env'))) {
    try {
        // 2. Jika .env ada, baru cek koneksi DB dan tabel users
        // Gunakan try-catch agar tidak error 500 jika DB credentials salah/kosong
        if (Schema::hasTable('users') && DB::table('users')->count() > 0) {
            $isInstalled = true;
        }
    } catch (\Throwable $e) {
        // Jika error koneksi DB, anggap belum terinstall dengan benar
        $isInstalled = false;
    }
} else {
    // Jika .env tidak ada, pasti belum terinstall
    $isInstalled = false;
}

// Redirect logic
if (!$isInstalled) {
    // Aktifkan route installer
    Route::controller(InstallController::class)->group(function () {
        Route::get('install/step-1', 'installStep1')->name('install-step-1');
        Route::get('install/step-2', 'installStep2')->name('install-step-2');
        Route::get('install/step-3', 'installStep3')->name('install-step-3');
        Route::post('install/process', 'installProcess')->name('install-process');
        Route::get('install/step-4', 'installStep4')->name('install-step-4');
    });

    // Jika user mengakses halaman lain (bukan installer), redirect ke installer
    Route::get('/', function () {
        return redirect()->route('install-step-1'); });
    Route::get('/dashboard', function () {
        return redirect()->route('install-step-1'); });
    // Note: Kita tidak me-redirect semua route (*) untuk menghindari conflict dengan asset/static files
} else {
    // Jika sudah terinstall, disable installer routes
    Route::get('install/{any?}', function () {
        return redirect('/');
    })->where('any', '.*');
}

Auth::routes();

// Company setup remains reachable before business context exists and before Common reads business data.
Route::middleware('auth')->group(function () {
    Route::get('company/financial-years/setup', [\App\Http\Controllers\CompanyFinancialYearController::class, 'index'])->name('company.financial-years.setup');
    Route::post('company/financial-years/setup', [\App\Http\Controllers\CompanyFinancialYearController::class, 'store'])->name('company.financial-years.store');
    // Post-login group/company picker; it chooses the context, so it runs before company middleware.
    Route::get('select-company', [\App\Http\Controllers\CompanySelectorController::class, 'index'])->name('company.select');
    Route::post('select-company', [\App\Http\Controllers\CompanySelectorController::class, 'choose'])->name('company.choose');
    Route::post('company-picker/groups', [\App\Http\Controllers\CompanySelectorController::class, 'storeGroup'])->name('company.groups.store');
    Route::post('company-picker/companies', [\App\Http\Controllers\CompanySelectorController::class, 'storeCompany'])->name('company.companies.store');
});

Route::group(['middleware' => 'auth'], function () {
    Route::controller(HomeController::class)->group(function () {
        Route::get('home', 'home');
    });
});

Route::group(['middleware' => ['common', 'auth', 'active', 'legacy.company']], function () {

    // Company document attachments are delivered only to members of the owning company.
    Route::get('secure-documents/{folder}/{file}', [\App\Http\Controllers\DocumentFileController::class, 'show'])
        ->where(['folder' => '[a-z_-]+', 'file' => '[^/]+'])->name('documents.file');

    Route::get('/languages', [LanguageController::class, 'index'])->name('languages');
    Route::post('/languages/create', [LanguageController::class, 'store']);
    Route::post('/languages/{id}/set-default', [LanguageController::class, 'setDefault']);
    Route::put('/languages/{id}', [LanguageController::class, 'update']);
    Route::delete('/languages/{id}', [LanguageController::class, 'destroy']);

    Route::get('/translations', [TranslationController::class, 'index'])->name('translations');
    Route::get('/translations/{locale}', [TranslationController::class, 'fetchByLanguage']);
    Route::post('/translations', [TranslationController::class, 'store']);
    Route::put('/translations/{id}', [TranslationController::class, 'update']);
    Route::delete('/translations/{id}', [TranslationController::class, 'destroy']);

    Route::controller(HomeController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/dashboard', 'dashboard')->name('dashboard');

        Route::get('new-release', 'newVersionReleasePage')->name('new-release');
        Route::post('version-upgrade', 'versionUpgrade')->name('version-upgrade');

        Route::get('/yearly-best-selling-price', 'yearlyBestSellingPrice');
        Route::get('/yearly-best-selling-qty', 'yearlyBestSellingQty');
        Route::get('/monthly-best-selling-qty', 'monthlyBestSellingQty');
        Route::get('/recent-sale', 'recentSale');
        Route::get('/recent-purchase', 'recentPurchase');
        Route::get('/recent-quotation', 'recentQuotation');
        Route::get('/recent-payment', 'recentPayment');
        Route::get('switch-theme/{theme}', 'switchTheme')->name('switchTheme');
        Route::get('/dashboard-filter/{start_date}/{end_date}/{warehouse_id}', 'dashboardFilter');
        Route::get('addon-list', 'addonList');
        Route::get('my-transactions/{year}/{month}', 'myTransaction');
    });

    // Need to check again
    Route::resource('products', ProductController::class)->except(['show']);
    Route::controller(ProductController::class)->group(function () {
        Route::post('products/product-data', 'productData');
        Route::get('products/gencode', 'generateCode')->name('product.gencode');
        Route::get('products/search', 'search');
        Route::get('products/saleunit/{id}', 'saleUnit')->name('product-saleunit');
        Route::get('products/getdata/{id}/{variant_id}', 'getData')->name('products.getdata');
        Route::get('products/product_warehouse/{id}', 'productWarehouseData')->name('product.warehouse');
        Route::get('products/print_barcode', 'printBarcode')->name('product.printBarcode');
        Route::get('products/lims_product_search', 'limsProductSearch')->name('product.search');
        Route::post('products/deletebyselection', 'deleteBySelection')->name('products.deletebyselection');
        Route::post('products/update', 'updateProduct');
        Route::get('products/variant-data/{id}', 'variantData');
        Route::get('products/history', 'history')->name('products.history');
        Route::post('products/sale-history-data', 'saleHistoryData');
        Route::post('products/purchase-history-data', 'purchaseHistoryData');
        Route::post('products/sale-return-history-data', 'saleReturnHistoryData');
        Route::post('products/purchase-return-history-data', 'purchaseReturnHistoryData');

        Route::post('importproduct', 'importProduct')->name('product.import');
        Route::post('exportproduct', 'exportProduct')->name('product.export');
        Route::get('products/all-product-in-stock', 'allProductInStock')->name('product.allProductInStock');
        Route::get('products/show-all-product-online', 'showAllProductOnline')->name('product.showAllProductOnline');
        Route::get('check-batch-availability/{product_id}/{batch_no}/{warehouse_id}', 'checkBatchAvailability');
        Route::get('product-price/{id}', 'getProductPrice');
    });

    Route::post('products/quick-store', [App\Http\Controllers\CommercialController::class, 'quickStoreProduct'])->name('products.quick-store');
    Route::post('categories/quick-store', [App\Http\Controllers\CommercialController::class, 'quickStoreCategory'])->name('categories.quick-store');
    Route::post('brands/quick-store', [App\Http\Controllers\CommercialController::class, 'quickStoreBrand'])->name('brands.quick-store');
    Route::post('parties/quick-store', [App\Http\Controllers\CommercialController::class, 'quickStoreParty'])->name('parties.quick-store');
    Route::post('parties/gst-lookup', [App\Http\Controllers\CommercialController::class, 'gstLookup'])->name('parties.gst-lookup');



    Route::get('language_switch/{id}', [LanguageController::class, 'switchLanguage']);

    Route::resource('role', RoleController::class);
    Route::controller(RoleController::class)->group(function () {
        Route::get('role/permission/{id}', 'permission')->name('role.permission');
        Route::post('role/set_permission', 'setPermission')->name('role.setPermission');
    });

    //Sms Template
    Route::resource('smstemplates', SmsTemplateController::class);
    Route::resource('unit', UnitController::class);
    Route::controller(UnitController::class)->group(function () {
        Route::post('importunit', 'importUnit')->name('unit.import');
        Route::post('unit/deletebyselection', 'deleteBySelection');
        Route::get('unit/lims_unit_search', 'limsUnitSearch')->name('unit.search');
    });

    Route::controller(CategoryController::class)->group(function () {
        Route::post('category/import', 'import')->name('category.import');
        Route::post('category/deletebyselection', 'deleteBySelection');
        Route::post('category/category-data', 'categoryData');
    });
    Route::resource('category', CategoryController::class);


    Route::controller(BrandController::class)->group(function () {
        Route::post('importbrand', 'importBrand')->name('brand.import');
        Route::post('brand/deletebyselection', 'deleteBySelection');
        Route::get('brand/lims_brand_search', 'limsBrandSearch')->name('brand.search');
    });
    Route::resource('brand', BrandController::class);


    Route::controller(SupplierController::class)->group(function () {
        Route::post('importsupplier', 'importSupplier')->name('supplier.import');
        Route::post('supplier/deletebyselection', 'deleteBySelection');
        Route::post('suppliers/clear-due', 'clearDue')->name('supplier.clearDue');
        Route::get('suppliers/all', 'suppliersAll')->name('supplier.all');
        Route::get('suppliers/ledger/{id}', 'ledger')->name('suppliers.ledger');
        Route::get('supplier-due/{id}', 'supplierDue')->name('supplier.due');
        Route::get('suppliers/{supplier_id}', 'supplierPayments')->name('suppliers.payments');
    });
    Route::resource('supplier', SupplierController::class);


    Route::controller(WarehouseController::class)->group(function () {
        Route::post('importwarehouse', 'importWarehouse')->name('warehouse.import');
        Route::post('warehouse/deletebyselection', 'deleteBySelection');
        Route::get('warehouse/lims_warehouse_search', 'limsWarehouseSearch')->name('warehouse.search');
        Route::get('warehouse/all', 'warehouseAll')->name('warehouse.all');
    });
    Route::resource('warehouse', WarehouseController::class);

    Route::resource('printers', PrinterController::class);

    Route::resource('tables', TableController::class);
    Route::resource('area', AreaController::class);
    Route::resource('agent', AgentController::class);
    Route::resource('bill-sundry', BillSundryController::class);
    Route::resource('sale-type', SaleTypeController::class);
    Route::resource('purchase-type', PurchaseTypeController::class);
    Route::resource('standard-remark', StandardRemarkController::class);
    Route::resource('document-series', DocumentSeriesController::class);
    Route::post('delivery-challans/{id}/convert-to-sale', [DeliveryChallanController::class, 'convertToSale'])->name('delivery-challans.convert-to-sale');
    Route::resource('delivery-challans', DeliveryChallanController::class);
    Route::post('goods-received-notes/{id}/convert-to-purchase', [GoodsReceivedNoteController::class, 'convertToPurchase'])->name('goods-received-notes.convert-to-purchase');
    Route::resource('goods-received-notes', GoodsReceivedNoteController::class);


    Route::controller(TaxController::class)->group(function () {
        Route::post('importtax', 'importTax')->name('tax.import');
        Route::post('tax/deletebyselection', 'deleteBySelection');
        Route::get('tax/lims_tax_search', 'limsTaxSearch')->name('tax.search');
    });
    Route::resource('tax', TaxController::class);


    Route::controller(CustomerGroupController::class)->group(function () {
        Route::post('importcustomer_group', 'importCustomerGroup')->name('customer_group.import');
        Route::post('customer_group/deletebyselection', 'deleteBySelection');
        Route::get('customer_group/lims_customer_group_search', 'limsCustomerGroupSearch')->name('customer_group.search');
        Route::get('customer_group/all', 'customerGroupAll')->name('customer_group.all');
    });
    Route::resource('customer_group', CustomerGroupController::class);


    Route::resource('discount-plans', DiscountPlanController::class);
    Route::resource('discounts', DiscountController::class);
    Route::get('discounts/product-search/{code}', [DiscountController::class, 'productSearch']);


    Route::controller(CustomerController::class)->group(function () {
        Route::post('importcustomer', 'importCustomer')->name('customer.import');
        Route::post('customer/deletebyselection', 'deleteBySelection');
        Route::get('customer/lims_customer_search', 'limsCustomerSearch')->name('customer.search');
        Route::post('customers/clear-due', 'clearDue')->name('customer.clearDue');
        Route::post('customers/customer-data', 'customerData');
        Route::get('customers/all', 'customersAll')->name('customer.all');

        // customer deposit route
        Route::get('customer/getDeposit/{id}', 'getDeposit');
        Route::post('customer/add_deposit', 'addDeposit')->name('customer.addDeposit');
        Route::post('customer/update_deposit', 'updateDeposit')->name('customer.updateDeposit');
        Route::post('customer/deleteDeposit', 'deleteDeposit')->name('customer.deleteDeposit');

        //customer points route
        Route::post('customer/deletePoints', 'deletePoints')->name('customer.deletePoints');
        Route::post('customer/add-point', 'addPoint')->name('customer.addPoint');
        Route::get('customer/getPoints/{id}', 'getPoints');
        Route::post('customer/update_point', 'updatePoint')->name('customer.updatePoint');
        Route::get('customers/{customer_id}', 'customerPayments')->name('customers.payments');
        Route::get('customers/ledger/{id}', 'ledger')->name('customers.ledger');
    });

    Route::resource('customer', CustomerController::class)->where(['customer' => '[0-9]+']);


    Route::controller(BillerController::class)->group(function () {
        Route::post('importbiller', 'importBiller')->name('biller.import');
        Route::post('biller/deletebyselection', 'deleteBySelection');
        Route::get('biller/lims_biller_search', 'limsBillerSearch')->name('biller.search');
    });
    Route::resource('biller', BillerController::class);


    Route::controller(SaleController::class)->group(function () {
        Route::post('sales/sale-data', 'saleData');
        Route::post('sales/sendmail', 'sendMail')->name('sale.sendmail');
        Route::get('sales/sale_by_csv', 'saleByCsv')->middleware('permission:sales-import');
        Route::get('sales/deleted_data', 'showDeletedSales')
            ->middleware('hasPermanentDeletePermission');
        Route::delete('sales/force-delete-selected', 'forceDeleteSelected')
            ->name('sales.forceDeleteSelected')
            ->middleware('hasPermanentDeletePermission');
        Route::get('sales/product_sale/{id}', 'productSaleData');
        Route::get('sales/get-sale/{id}', 'getSale');
        Route::get('sales/{id}/json', 'getSaleJson');
        Route::post('importsale', 'importSale')->name('sale.import');
        Route::get('pos/{id?}', 'posSale')->name('sale.pos');
        Route::get('sales/recent-sale', 'recentSale');
        Route::get('sales/recent-draft', 'recentDraft');
        Route::get('sales/lims_sale_search', 'limsSaleSearch')->name('sale.search');
        Route::get('sales/lims_product_search', 'limsProductSearch')->name('product_sale.search');
        Route::get('sales/getcustomergroup/{id}', 'getCustomerGroup')->name('sale.getcustomergroup');

        Route::get('sales/getproduct/{id}', 'getProduct')->name('sale.getproduct');

        Route::get('sales/getproducts/{warehouse_id}/{key}/{value}', 'getProducts');

        Route::get('sales/search/{warehouse_id}/{search}', 'search');

        Route::get('sales/get_gift_card', 'getGiftCard');
        Route::get('sales/paypalSuccess', 'paypalSuccess');
        Route::get('sales/paypalPaymentSuccess/{id}', 'paypalPaymentSuccess');
        Route::get('sales/gen_invoice/{id}', 'genInvoice')->name('sale.invoice');
        Route::post('sales/add_payment', 'addPayment')->name('sale.add-payment');
        Route::get('sales/getpayment/{id}', 'getPayment')->name('sale.get-payment');
        Route::post('sales/updatepayment', 'updatePayment')->name('sale.update-payment');
        Route::post('sales/deletepayment', 'deletePayment')->name('sale.delete-payment');
        Route::get('sales/{id}/create', 'createSale')->name('sale.draft');
        Route::post('sales/deletebyselection', 'deleteBySelection');
        Route::get('customer-display', 'customerDisplay')->name('sales.customerDisplay');
        Route::get('sales/print-last-reciept', 'printLastReciept')->name('sales.printLastReciept');
        Route::get('sales/today-sale', 'todaySale');
        Route::get('sales/today-profit/{warehouse_id}', 'todayProfit');
        Route::get('sales/check-discount', 'checkDiscount');
        Route::get('sales/get-sold-items/{id}', 'getSoldItem');
        Route::post('sales/sendsms', 'sendSMS')->name('sale.sendsms');
        Route::post('sales/whatsapp-notification', 'whatsappNotificationSend')->name('sale.wappnotification');
        Route::get('customer-sales/{customer_id}', 'customerSales')->name('sales.customer');
    });
    Route::resource('sales', SaleController::class)->except('show');

    Route::get('/installmentplan/{id}', [InstallmentPlanController::class, 'show'])->name('installmentplan.show');

    Route::post('/razorpay/pay', [RazorpayController::class, 'createOrder']);
    Route::post('/razorpay/verify', [RazorpayController::class, 'verifyPayment']);

    Route::controller(PackingSlipController::class)->group(function () {
        Route::prefix('packing-slips')->group(function () {
            Route::get('/', 'index')->name('packingSlip.index');
            Route::post('packing-slip-data', 'packingSlipData');
            Route::post('store', 'store')->name('packingSlip.store');
            Route::post('delete/{id}', 'delete')->name('packingSlip.delete');
            Route::get('invoice/{id}', 'genInvoice')->name('packingSlip.genInvoice');
        });
    });

    Route::controller(ChallanController::class)->group(function () {
        Route::prefix('challans')->group(function () {
            Route::get('/', 'index')->name('challan.index');
            Route::post('challan-data', 'challanData');
            Route::post('create', 'create')->name('challan.create');
            Route::post('store', 'store')->name('challan.store');
            Route::get('invoice/{id}', 'genInvoice')->name('challan.genInvoice');
            Route::get('money-reciept/{id}', 'moneyReciept')->name('challan.moneyReciept');
            Route::get('finalize/{id}', 'finalize')->name('challan.finalize');
            Route::post('update/{id}', 'update')->name('challan.update');
        });
    });

    Route::controller(DeliveryController::class)->group(function () {
        Route::prefix('delivery')->group(function () {
            Route::get('/', 'index')->name('delivery.index');
            Route::get('delivery_list_data', 'deliveryListData');
            Route::get('product_delivery/{id}', 'productDeliveryData');
            Route::get('create/{id}', 'create');
            Route::post('store', 'store')->name('delivery.store');
            Route::post('sendmail', 'sendMail')->name('delivery.sendMail');
            Route::get('{id}/edit', 'edit');
            Route::post('update', 'update')->name('delivery.update');
            Route::post('deletebyselection', 'deleteBySelection');
            Route::post('delete/{id}', 'delete')->name('delivery.delete');
        });
    });


    Route::controller(QuotationController::class)->group(function () {
        Route::prefix('quotations')->group(function () {
            Route::post('quotation-data', 'quotationData')->name('quotations.data');
            Route::get('product_quotation/{id}', 'productQuotationData');
            Route::get('lims_product_search', 'limsProductSearch')->name('product_quotation.search');
            Route::get('getcustomergroup/{id}', 'getCustomerGroup')->name('quotation.getcustomergroup');
            Route::get('getproduct/{id}', 'getProduct')->name('quotation.getproduct');
            Route::get('{id}/create_sale', 'createSale')->name('quotation.create_sale');
            Route::get('{id}/create_purchase', 'createPurchase')->name('quotation.create_purchase');
            Route::post('sendmail', 'sendMail')->name('quotation.sendmail');
            Route::post('deletebyselection', 'deleteBySelection');
        });
    });
    Route::resource('quotations', QuotationController::class);


    Route::controller(PurchaseController::class)->group(function () {
        Route::prefix('purchases')->group(function () {
            Route::post('purchase-data', 'purchaseData')->name('purchases.data');
            Route::get('product_purchase/{id}', 'productPurchaseData');
            Route::get('lims_product_search', 'limsProductSearch')->name('product_purchase.search');
            Route::post('add_payment', 'addPayment')->name('purchase.add-payment');
            Route::get('getpayment/{id}', 'getPayment')->name('purchase.get-payment');
            Route::post('updatepayment', 'updatePayment')->name('purchase.update-payment');
            Route::post('deletepayment', 'deletePayment')->name('purchase.delete-payment');
            Route::get('purchase_by_csv', 'purchaseByCsv')->middleware('permission:purchases-import');
            Route::get('deleted_data', 'showDeletedPurchases')
                ->middleware('hasPermanentDeletePermission');
            Route::get('duplicate/{id}', 'duplicate')->name('purchase.duplicate');
            Route::post('deletebyselection', 'deleteBySelection');
            Route::delete('force-delete-selected', 'forceDeleteSelected')
                ->name('purchases.forceDeleteSelected')
                ->middleware('hasPermanentDeletePermission');
            Route::get('supplier/{supplier_id}', 'supplierPurchase')->name('purchase.supplier');
        });
        Route::post('importpurchase', 'importPurchase')->name('purchase.import');
    });
    Route::resource('purchases', PurchaseController::class);



    Route::controller(TransferController::class)->group(function () {
        Route::prefix('transfers')->group(function () {
            Route::post('transfer-data', 'transferData')->name('transfers.data');
            Route::get('product_transfer/{id}', 'productTransferData');
            Route::get('transfer_by_csv', 'transferByCsv')->middleware('permission:transfers-import');
            Route::get('getproduct/{id}', 'getProduct')->name('transfers.getproduct');
            Route::put('change-status/{id}', 'changeStatus')->name('transfers.changeStatus');
            Route::get('lims_product_search', 'limsProductSearch')->name('product_transfer.search');
            Route::post('deletebyselection', 'deleteBySelection');
        });
        Route::post('importtransfer', 'importTransfer')->name('transfer.import');
    });
    Route::resource('transfers', TransferController::class);



    Route::controller(AdjustmentController::class)->group(function () {
        Route::get('qty_adjustment/getproduct/{id}', 'getProduct')->name('adjustment.getproduct');
        Route::get('qty_adjustment/lims_product_search', 'limsProductSearch')->name('product_adjustment.search');
        Route::post('qty_adjustment/deletebyselection', 'deleteBySelection');
    });
    Route::resource('qty_adjustment', AdjustmentController::class);


    Route::controller(ReturnController::class)->group(function () {
        Route::prefix('return-sale')->group(function () {
            Route::post('return-data', 'returnData');
            Route::get('getcustomergroup/{id}', 'getCustomerGroup')->name('return-sale.getcustomergroup');
            Route::post('sendmail', 'sendMail')->name('return-sale.sendmail');
            Route::get('getproduct/{id}', 'getProduct')->name('return-sale.getproduct');
            Route::get('lims_product_search', 'limsProductSearch')->name('product_return-sale.search');
            Route::get('product_return/{id}', 'productReturnData');
            Route::post('deletebyselection', 'deleteBySelection');
        });
    });
    Route::resource('return-sale', ReturnController::class);


    Route::controller(ReturnPurchaseController::class)->group(function () {
        Route::prefix('return-purchase')->group(function () {
            Route::post('return-data', 'returnData');
            Route::get('getcustomergroup/{id}', 'getCustomerGroup')->name('return-purchase.getcustomergroup');
            Route::post('sendmail', 'sendMail')->name('return-purchase.sendmail');
            Route::get('getproduct/{id}', 'getProduct')->name('return-purchase.getproduct');
            Route::get('lims_product_search', 'limsProductSearch')->name('product_return-purchase.search');
            Route::get('product_return/{id}', 'productReturnData');
            Route::post('deletebyselection', 'deleteBySelection');
        });
    });
    Route::resource('return-purchase', ReturnPurchaseController::class);


    Route::controller(ReportController::class)->group(function () {
        Route::prefix('report')->group(function () {
            Route::get('product_quantity_alert', 'productQuantityAlert')->name('report.qtyAlert');
            Route::get('daily-sale-objective', 'dailySaleObjective')->name('report.dailySaleObjective');
            Route::post('daily-sale-objective-data', 'dailySaleObjectiveData');
            Route::get('product-expiry', 'productExpiry')->name('report.productExpiry');
            Route::get('warehouse_stock', 'warehouseStock')->name('report.warehouseStock');
            Route::get('daily_sale/{year}/{month}', 'dailySale');
            Route::post('daily_sale/{year}/{month}', 'dailySaleByWarehouse')->name('report.dailySaleByWarehouse');
            Route::get('monthly_sale/{year}', 'monthlySale');
            Route::post('monthly_sale/{year}', 'monthlySaleByWarehouse')->name('report.monthlySaleByWarehouse');
            Route::get('daily_purchase/{year}/{month}', 'dailyPurchase');
            Route::post('daily_purchase/{year}/{month}', 'dailyPurchaseByWarehouse')->name('report.dailyPurchaseByWarehouse');
            Route::get('monthly_purchase/{year}', 'monthlyPurchase');
            Route::post('monthly_purchase/{year}', 'monthlyPurchaseByWarehouse')->name('report.monthlyPurchaseByWarehouse');
            Route::get('best_seller', 'bestSeller');
            Route::post('best_seller', 'bestSellerByWarehouse')->name('report.bestSellerByWarehouse');
            Route::post('profit_loss', 'profitLoss')->name('report.profitLoss');
            Route::get('product_report', 'productReport')->name('report.product');
            Route::post('product_report_data', 'productReportData');
            Route::post('purchase', 'purchaseReport')->name('report.purchase');
            Route::post('purchase_report_data', 'purchaseReportData');
            Route::post('sale_report', 'saleReport')->name('report.sale');
            Route::post('sale_report_data', 'saleReportData');
            Route::get('challan-report', 'challanReport')->name('report.challan');
            Route::post('sale-report-chart', 'saleReportChart')->name('report.saleChart');
            Route::post('payment_report_by_date', 'paymentReportByDate')->name('report.paymentByDate');
            Route::post('warehouse_report', 'warehouseReport')->name('report.warehouse');
            Route::post('warehouse-sale-data', 'warehouseSaleData');
            Route::post('warehouse-purchase-data', 'warehousePurchaseData');
            Route::post('warehouse-expense-data', 'warehouseExpenseData');
            Route::post('warehouse-quotation-data', 'warehouseQuotationData');
            Route::post('warehouse-return-data', 'warehouseReturnData');
            Route::post('user_report', 'userReport')->name('report.user');
            Route::post('user-sale-data', 'userSaleData');
            Route::post('user-purchase-data', 'userPurchaseData');
            Route::post('user-expense-data', 'userExpenseData');
            Route::post('user-quotation-data', 'userQuotationData');
            Route::post('user-payment-data', 'userPaymentData');
            Route::post('user-transfer-data', 'userTransferData');
            Route::post('user-payroll-data', 'userPayrollData');
            Route::post('biller_report', 'billerReport')->name('report.biller');
            Route::post('biller-sale-data', 'billerSaleData');
            Route::post('biller-quotation-data', 'billerQuotationData');
            Route::post('biller-payment-data', 'billerPaymentData');
            Route::post('customer_report', 'customerReport')->name('report.customer');
            Route::post('customer-sale-data', 'customerSaleData');
            Route::post('customer-payment-data', 'customerPaymentData');
            Route::post('customer-quotation-data', 'customerQuotationData');
            Route::post('customer-return-data', 'customerReturnData');
            Route::post('customer-group', 'customerGroupReport')->name('report.customer_group');
            Route::post('customer-group-sale-data', 'customerGroupSaleData');
            Route::post('customer-group-payment-data', 'customerGroupPaymentData');
            Route::post('customer-group-quotation-data', 'customerGroupQuotationData');
            Route::post('customer-group-return-data', 'customerGroupReturnData');
            Route::post('supplier', 'supplierReport')->name('report.supplier');
            Route::post('supplier-purchase-data', 'supplierPurchaseData');
            Route::post('supplier-payment-data', 'supplierPaymentData');
            Route::post('supplier-return-data', 'supplierReturnData');
            Route::post('supplier-quotation-data', 'supplierQuotationData');
            Route::post('customer-due-report', 'customerDueReportByDate')->name('report.customerDueByDate');
            Route::post('customer-due-report-data', 'customerDueReportData');
            Route::post('supplier-due-report', 'supplierDueReportByDate')->name('report.supplierDueByDate');
            Route::post('supplier-due-report-data', 'supplierDueReportData');
        });
    });


    Route::controller(UserController::class)->group(function () {
        Route::get('user/profile/{id}', 'profile')->name('user.profile');
        Route::put('user/update_profile/{id}', 'profileUpdate')->name('user.profileUpdate');
        Route::put('user/changepass/{id}', 'changePassword')->name('user.password');
        Route::get('user/genpass', 'generatePassword');
        Route::post('user/deletebyselection', 'deleteBySelection');
        Route::get('user/notification', 'notificationUsers')->name('user.notification');
        Route::get('user/all', 'allUsers')->name('user.all');
        Route::post('user/toggle-status', [UserController::class, 'toggleStatus'])->name('user.toggleStatus');

    });
    Route::resource('user', UserController::class);


    Route::controller(SettingController::class)->group(function () {
        Route::prefix('setting')->group(function () {
            Route::get('activity-log', 'activityLog')->name('setting.activityLog');
            Route::get('general_setting', 'generalSetting')->name('setting.general');
            Route::post('general_setting_store', 'generalSettingStore')->name('setting.generalStore');

            Route::get('reward-point-setting', 'rewardPointSetting')->name('setting.rewardPoint');
            Route::post('reward-point-setting_store', 'rewardPointSettingStore')->name('setting.rewardPointStore');

            Route::get('general_setting/change-theme/{theme}', 'changeTheme');
            Route::get('mail_setting', 'mailSetting')->name('setting.mail');
            Route::get('sms_setting', 'smsSetting')->name('setting.sms');
            Route::get('createsms', 'createSms')->name('setting.createSms');
            Route::post('sendsms', 'sendSMS')->name('setting.sendSms');
            Route::get('payment-gateways/list', 'gateway')->name('setting.gateway');
            Route::post('payment-gateways/update', 'gatewayUpdate')->name('setting.gateway.update');
            Route::get('hrm_setting', 'hrmSetting')->name('setting.hrm');
            Route::post('hrm_setting_store', 'hrmSettingStore')->name('setting.hrmStore');
            Route::post('mail_setting_store', 'mailSettingStore')->name('setting.mailStore');
            Route::post('sms_setting_store', 'smsSettingStore')->name('setting.smsStore');
            Route::get('pos_setting', 'posSetting')->name('setting.pos');
            Route::post('pos_setting_store', 'posSettingStore')->name('setting.posStore');
            Route::post('empty-database', 'emptyDatabase')->name('setting.emptyDatabase');

        });
        Route::get('backup', 'backup')->name('setting.backup');
    });

    Route::prefix('setting')->name('settings.')->group(function () {
        Route::resource('invoice', InvoiceSettingController::class);
    });

    Route::get('/barcodes/set_default/{id}', [BarcodeController::class, 'setDefault']);
    Route::controller(BarcodeController::class)->group(function () {
        Route::post('barcodes/barcode-data', 'barcodeData')->name('barcodes.data');
    });
    Route::resource('barcodes', BarcodeController::class);


    Route::get('/labels/show', [LabelsController::class, 'show'])->name('print.labels');
    Route::get('/labels/add-product-row', [LabelsController::class, 'addProductRow']);
    Route::get('/labels/print', [LabelsController::class, 'printLabel'])->name('print.label');

    Route::controller(ExpenseCategoryController::class)->group(function () {
        Route::get('expense_categories/gencode', 'generateCode');
        Route::post('expense_categories/import', 'import')->name('expense_category.import');
        Route::post('expense_categories/deletebyselection', 'deleteBySelection');
        Route::get('expense_categories/all', 'expenseCategoriesAll')->name('expense_category.all');
        ;
    });
    Route::resource('expense_categories', ExpenseCategoryController::class);


    Route::controller(ExpenseController::class)->group(function () {
        Route::post('expenses/expense-data', 'expenseData')->name('expenses.data');
        Route::post('expenses/deletebyselection', 'deleteBySelection');
    });
    Route::resource('expenses', ExpenseController::class);

    // IncomeCategory & Income Start
    Route::controller(IncomeCategoryController::class)->group(function () {
        Route::get('income_categories/gencode', 'generateCode');
        Route::post('income_categories/import', 'import')->name('income_category.import');
        Route::post('income_categories/deletebyselection', 'deleteBySelection');
        Route::get('income_categories/all', 'incomeCategoriesAll')->name('income_category.all');
        ;
    });
    Route::resource('income_categories', IncomeCategoryController::class);


    Route::controller(IncomeController::class)->group(function () {
        Route::post('incomes/income-data', 'incomeData')->name('incomes.data');
        Route::post('incomes/deletebyselection', 'deleteBySelection');
    });
    Route::resource('incomes', IncomeController::class);
    // IncomeCategory & Income End


    Route::controller(GiftCardController::class)->group(function () {
        Route::get('gift_cards/gencode', 'generateCode');
        Route::post('gift_cards/recharge/{id}', 'recharge')->name('gift_cards.recharge');
        Route::post('gift_cards/deletebyselection', 'deleteBySelection');
    });
    Route::resource('gift_cards', GiftCardController::class);

    Route::resource('couriers', CourierController::class);

    Route::controller(CouponController::class)->group(function () {
        Route::get('coupons/gencode', 'generateCode');
        Route::post('coupons/deletebyselection', 'deleteBySelection');
    });
    Route::resource('coupons', CouponController::class);

    Route::get('phpfileinfo', function () {
        phpinfo();
    })->name('phpfileinfo');


    //accounting routes
    Route::controller(AccountsController::class)->group(function () {
        Route::get('make-default/{id}', 'makeDefault');
        Route::get('balancesheet', 'balanceSheet')->name('accounts.balancesheet');
        Route::post('account-statement', 'accountStatement')->name('accounts.statement');
        Route::get('accounts/all', 'accountsAll')->name('account.all');
    });
    Route::resource('accounts', AccountsController::class);

    // Double-Entry Accounting & Financial Ledger Routes
    Route::prefix('accounting')->middleware('company.context')->group(function () {
        Route::get('chart-of-accounts', [\App\Http\Controllers\Accounting\ChartOfAccountsController::class, 'index'])->name('accounting.coa');
        Route::post('chart-of-accounts', [\App\Http\Controllers\Accounting\ChartOfAccountsController::class, 'store'])->name('accounting.coa.store');
        Route::put('chart-of-accounts/{id}', [\App\Http\Controllers\Accounting\ChartOfAccountsController::class, 'update'])->name('accounting.coa.update');

        Route::get('journal-entries', [\App\Http\Controllers\Accounting\JournalEntryController::class, 'index'])->name('accounting.journal-entries');
        Route::post('journal-entries', [\App\Http\Controllers\Accounting\JournalEntryController::class, 'store'])->name('accounting.journal-entries.store');
        Route::get('journal-entries/{id}', [\App\Http\Controllers\Accounting\JournalEntryController::class, 'show'])->name('accounting.journal-entries.show');
        Route::get('voucher/entry', [\App\Http\Controllers\Accounting\JournalEntryController::class, 'voucherEntry'])->name('accounting.voucher.entry');
        Route::post('voucher/inline-account', [\App\Http\Controllers\Accounting\JournalEntryController::class, 'inlineAccount'])->name('accounting.voucher.inline-account');
        Route::get('voucher/next-number', [\App\Http\Controllers\Accounting\JournalEntryController::class, 'nextVoucherNumber'])->name('accounting.voucher.next-number');

        Route::get('trial-balance', [\App\Http\Controllers\Accounting\FinancialReportController::class, 'trialBalance'])->name('accounting.trial-balance');
        Route::get('profit-loss', [\App\Http\Controllers\Accounting\FinancialReportController::class, 'profitLoss'])->name('accounting.profit-loss');
        Route::get('balance-sheet', [\App\Http\Controllers\Accounting\FinancialReportController::class, 'balanceSheet'])->name('accounting.balance-sheet');
        Route::get('general-ledger', [\App\Http\Controllers\Accounting\FinancialReportController::class, 'generalLedger'])->name('accounting.general-ledger');
    });


    Route::resource('money-transfers', MoneyTransferController::class);


    //HRM routes
    Route::post('departments/deletebyselection', [DepartmentController::class, 'deleteBySelection']);
    Route::resource('departments', DepartmentController::class);
    Route::resource('designations', DesignationController::class);
    Route::resource('shift', ShiftController::class);
    Route::resource('overtime', OvertimeController::class);
    Route::resource('leave-type', LeaveTypeController::class);
    Route::resource('leave', LeaveController::class);
    Route::get('hrm-panel', [HrmController::class, 'index'])->name('hrm-panel');
    Route::resource('sale-agents', SaleAgentController::class)->except('show');
    Route::get('/payroll/monthly-data', [PayrollController::class, 'monthlyData'])->name('payroll.monthlyData');
    Route::get('payroll/get-employees-by-warehouse', [PayrollController::class, 'getEmployeesByWarehouse'])->name('payroll.getEmployeesByWarehouse');
    Route::post('payroll/store-multiple', [PayrollController::class, 'storeMultiple'])->name('payroll.storeMultiple');
    Route::post('payroll/generate', [PayrollController::class, 'generateCards'])->name('payroll.generateCards');





    Route::post('employees/deletebyselection', [EmployeeController::class, 'deleteBySelection']);
    Route::resource('employees', EmployeeController::class);


    Route::post('payroll/deletebyselection', [PayrollController::class, 'deleteBySelection']);
    Route::resource('payroll', PayrollController::class);


    Route::post('attendance/delete/{date}/{employee_id}', [AttendanceController::class, 'delete'])->name('attendances.delete');
    Route::post('attendance/deletebyselection', [AttendanceController::class, 'deleteBySelection']);
    Route::post('attendance/importDeviceCsv', [AttendanceController::class, 'importDeviceCsv'])->name('attendances.importDeviceCsv');
    Route::resource('attendance', AttendanceController::class);

    Route::controller(StockCountController::class)->group(function () {
        Route::post('stock-count/finalize', 'finalize')->name('stock-count.finalize');
        Route::get('stock-count/stockdif/{id}', 'stockDif');
        Route::get('stock-count/{id}/qty_adjustment', 'qtyAdjustment')->name('stock-count.adjustment');
    });
    Route::resource('stock-count', StockCountController::class);


    Route::controller(HolidayController::class)->group(function () {
        Route::post('holidays/deletebyselection', 'deleteBySelection');
        Route::get('approve-holiday/{id}', 'approveHoliday')->name('approveHoliday');
        Route::get('holidays/my-holiday/{year}/{month}', 'myHoliday')->name('myHoliday');
    });
    Route::resource('holidays', HolidayController::class);


    Route::controller(CashRegisterController::class)->group(function () {
        Route::prefix('cash-register')->group(function () {
            Route::get('/', 'index')->name('cashRegister.index');
            Route::get('check-availability/{warehouse_id}', 'checkAvailability')->name('cashRegister.checkAvailability');
            Route::post('store', 'store')->name('cashRegister.store');
            Route::get('getDetails/{id}', 'getDetails');
            Route::post('close', 'close')->name('cashRegister.close');
        });
    });


    Route::controller(NotificationController::class)->group(function () {
        Route::prefix('notifications')->group(function () {
            Route::get('/', 'index')->name('notifications.index');
            Route::post('store', 'store')->name('notifications.store');
            Route::get('mark-as-read', 'markAsRead');
        });
    });


    Route::resource('currency', CurrencyController::class);

    Route::resource('custom-fields', CustomFieldController::class);

    Route::controller(AddonInstallController::class)->group(function () {
        Route::post('saas-install', 'saasInstall')->name('saas.install');
        Route::post('ecommerce-install', 'ecommerceInstall')->name('ecommerce.install');
        Route::post('woocommerce-install', 'woocommerceInstall')->name('woocommerce.install');
        Route::post('api-install', 'apiInstall')->name('api.install');
    });

    // Addon Management & SaaS Feature Toggles
    Route::post('addon/toggle', [HomeController::class, 'addonToggle'])->name('addon.toggle');

    // Module: Damage Stock Write-offs
    Route::controller(\App\Http\Controllers\DamageStockController::class)->group(function () {
        Route::get('damage-stock', 'index')->name('damage-stock.index');
        Route::post('damage-stock', 'store')->name('damage-stock.store');
    });

    // Module: Installment Plans & Consumer Financing
    Route::controller(\App\Http\Controllers\InstallmentPlanController::class)->group(function () {
        Route::get('installmentplan', 'index')->name('installmentplan.index');
        Route::post('installmentplan', 'store')->name('installmentplan.store');
    });

    // Module: Counter Product Exchange & Returns
    Route::controller(\App\Http\Controllers\ExchangeController::class)->group(function () {
        Route::get('exchange', 'index')->name('exchange.index');
        Route::get('exchange/create', 'create')->name('exchange.create');
        Route::post('exchange', 'store')->name('exchange.store');
    });

    // Accounting Extended: Cash Flow, Periodic Inventory Close, Semantic Mappings
    Route::prefix('accounting')->middleware('company.context')->group(function () {
        Route::controller(\App\Http\Controllers\Accounting\VoucherController::class)->group(function () {
            Route::get('vouchers', 'index')->name('accounting.vouchers');
            Route::post('vouchers', 'store')->name('accounting.vouchers.store');
            Route::post('journal-entries/{id}/reverse', 'reverse')->name('accounting.journal.reverse');
            Route::get('open-items', 'openItems')->name('accounting.open-items');
            Route::post('allocations', 'allocate')->name('accounting.allocations.store');
            Route::post('allocations/{id}/reverse', 'reverseAllocation')->name('accounting.allocations.reverse');
            Route::post('period', 'period')->name('accounting.period');
            Route::post('settlement-accounts/{id}/link', 'linkAccount')->name('accounting.settlement-accounts.link');
            Route::get('day-book', 'book')->defaults('type', 'day')->name('accounting.day-book');
            Route::get('cash-book', 'book')->defaults('type', 'cash')->name('accounting.cash-book');
            Route::get('ageing', 'ageing')->name('accounting.ageing');
            Route::get('monthly-ledger/{id}', 'monthly')->name('accounting.monthly-ledger');
        });
        Route::get('cash-flow-statement', [\App\Http\Controllers\Accounting\FinancialReportController::class, 'cashFlowStatement'])->name('accounting.cash-flow');
        Route::get('inventory-close', [\App\Http\Controllers\Accounting\PeriodicInventoryCloseController::class, 'index'])->name('accounting.inventory-close');
        Route::post('inventory-close', [\App\Http\Controllers\Accounting\PeriodicInventoryCloseController::class, 'postClose'])->name('accounting.inventory-close.store');
        Route::post('inventory-close/post', [\App\Http\Controllers\Accounting\PeriodicInventoryCloseController::class, 'postClose'])->name('accounting.inventory-close.post');
        Route::get('semantic-account-mappings', [\App\Http\Controllers\Accounting\SemanticMappingController::class, 'index'])->name('accounting.semantic-mappings');
        Route::post('semantic-account-mappings', [\App\Http\Controllers\Accounting\SemanticMappingController::class, 'update'])->name('accounting.semantic-mappings.store');
        Route::post('semantic-account-mappings/update', [\App\Http\Controllers\Accounting\SemanticMappingController::class, 'update'])->name('accounting.semantic-mappings.update');
    });

    // Module: Water Supply & Fleet Logistics
    Route::controller(\App\Http\Controllers\WaterLogisticsController::class)->group(function () {
        Route::get('water-logistics', 'index')->name('water-logistics.index');
        Route::post('water-logistics/trip-sheets', 'storeTripSheet')->name('water-logistics.trip-sheets.store');
        Route::post('water-logistics/trip', 'storeTripSheet')->name('water-logistics.trip.store');
        Route::post('water-logistics/can-deliveries', 'storeCanDelivery')->name('water-logistics.can-deliveries.store');
        Route::post('water-logistics/can', 'storeCanDelivery')->name('water-logistics.can.store');
        Route::get('water-logistics/credit-aging', 'creditAging')->name('water-logistics.credit-aging');
    });

    // Module: Cafe & Bakery Operations
    Route::controller(\App\Http\Controllers\CafeOperationsController::class)->group(function () {
        Route::get('cafe', 'index')->name('cafe.index');
        Route::get('cafe/pos', 'pos')->name('cafe.pos');
        Route::post('cafe/orders', 'storeOrder')->name('cafe.orders.store');
        Route::post('cafe/reconcile-drawer', 'reconcileDrawer')->name('cafe.reconcile-drawer');
        Route::post('cafe/drawer', 'reconcileDrawer')->name('cafe.drawer.store');
        Route::post('cafe/raw-materials', 'storeRawMaterial')->name('cafe.raw-materials.store');
        Route::post('cafe/raw-material', 'storeRawMaterial')->name('cafe.raw-material.store');
    });

    // Module: Dynamic Catalogue QR
    Route::controller(\App\Http\Controllers\CatalogueQrController::class)->group(function () {
        Route::get('qr', 'index')->name('qr.index');
        Route::post('qr', 'store')->name('qr.store');
        Route::get('menu/{slug}', 'viewCatalogue')->name('qr.menu');
    });

    // Module: Bookings & Appointments Calendar
    Route::controller(\App\Http\Controllers\BookingController::class)->group(function () {
        Route::get('bookings/calendar', 'calendar')->name('bookings.calendar');
        Route::post('bookings', 'store')->name('bookings.store');
    });

    // Module: Repair & Service Center
    Route::controller(\App\Http\Controllers\RepairController::class)->group(function () {
        Route::get('repair/dashboard', 'dashboard')->name('repair.dashboard');
        Route::get('repair/services', 'services')->name('repair.services');
        Route::post('repair/services', 'storeService')->name('repair.services.store');
        Route::get('repair/device-types', 'deviceTypes')->name('repair.device-types');
        Route::post('repair/device-types', 'storeDeviceType')->name('repair.device-types.store');
    });

    // Module: Project Management
    Route::controller(\App\Http\Controllers\ProjectManagementController::class)->group(function () {
        Route::get('project-management/projects', 'projects')->name('project-management.projects');
        Route::post('project-management/projects', 'storeProject')->name('project-management.projects.store');
        Route::get('project-management/categories', 'categories')->name('project-management.categories');
        Route::post('project-management/categories', 'storeCategory')->name('project-management.categories.store');
        Route::get('project-management/tasks', 'tasks')->name('project-management.tasks');
        Route::post('project-management/tasks', 'storeTask')->name('project-management.tasks.store');
        Route::post('project-management/tasks/{id}/status', 'updateTaskStatus')->name('project-management.tasks.status');
    });

    Route::prefix('whatsapp')->group(function () {
        Route::get('/settings', [WhatsappController::class, 'settings'])->name('whatsapp.settings');
        Route::post('/settings', [WhatsappController::class, 'updateSettings'])->name('whatsapp.settings.update');

        Route::get('/templates', [WhatsappController::class, 'templates'])->name('whatsapp.templates');
        Route::delete('/template/delete/{name}', [WhatsappController::class, 'deleteTemplate'])->name('whatsapp.template.delete');

        Route::get('/send', [WhatsappController::class, 'sendPage'])->name('whatsapp.send.page');
        Route::post('/send', [WhatsappController::class, 'sendMessage'])->name('whatsapp.send');
    });

    //ticket routes
    if (class_exists(\App\Http\Controllers\landlord\TicketController::class)) {
        Route::controller(\App\Http\Controllers\landlord\TicketController::class)->group(function () {
            Route::get('tickets', 'index')->name('tickets.index');
            Route::get('tickets/create', 'create')->name('tickets.create');
            Route::post('tickets', 'store')->name('tickets.store');
            Route::get('tickets/{id}', 'show')->name('tickets.show');
            Route::post('tickets/{id}/reply', 'reply')->name('tickets.reply');
            Route::delete('tickets/{id}', 'destroy')->name('tickets.destroy');
        });
    }

});
