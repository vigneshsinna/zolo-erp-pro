<?php

namespace App\Traits;

use App\Models\landlord\Tenant;
use Illuminate\Support\Str;
use DB;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\landlord\Package;
use App\Models\landlord\TenantPayment;
use App\Mail\TenantCreate;
use App\Models\landlord\MailSetting;
use Mail;
use Illuminate\Support\Facades\Artisan;
use Database\Seeders\Tenant\TenantDatabaseSeeder;
use Modules\Ecommerce\Database\Seeders\EcommerceDatabaseSeeder;

trait TenantInfo
{

    use \App\Traits\MailInfo;

    public function getTenantId()
    {
        return tenant()->id;
    }

    public function features()
    {
        $features = [
            'product_and_categories' => [
                'name' => 'Product and Categories',
                'default' => true,
                'permission_ids' => ''
            ],
            'purchase_and_sale' => [
                'name' => 'Purchase and Sale',
                'default' => true,
                'permission_ids' => ''
            ],
            'sale_return' => [
                'name' => 'Sale Return',
                'default' => false,
                'permission_ids' => '24,25,26,27,'
            ],
            'purchase_return' => [
                'name' => 'Purchase Return',
                'default' => false,
                'permission_ids' => '63,64,65,66,'
            ],
            'expense' => [
                'name' => 'Expense',
                'default' => false,
                'permission_ids' => '55,56,57,58,'
            ],
            'income' => [
                'name' => 'Income',
                'default' => false,
                'permission_ids' => '127,128,129,130,'
            ],
            'transfer' => [
                'name' => 'Stock Transfer',
                'default' => false,
                'permission_ids' => '20,21,22,23,'
            ],
            'quotation' => [
                'name' => 'Quotation',
                'default' => false,
                'permission_ids' => '16,17,18,19,'
            ],
            'delivery' => [
                'name' => 'Product Delivery',
                'default' => false,
                'permission_ids' => '99,'
            ],
            'stock_count_and_adjustment' => [
                'name' => 'Stock Count and Adjustment',
                'default' => false,
                'permission_ids' => '78,79,'
            ],
            'report' => [
                'name' => 'Report',
                'default' => false,
                'permission_ids' => '36,37,38,39,40,45,46,47,48,49,50,51,52,53,54,77,90,112,122,123,125,132,'
            ],
            'hrm' => [
                'name' => 'HRM',
                'default' => false,
                'permission_ids' => '62,70,71,72,73,74,75,76,89,'
            ],
            'accounting' => [
                'name' => 'Accounting',
                'default' => false,
                'permission_ids' => '67,68,69,97,'
            ]
        ];
        if(file_exists(base_path('Modules/Manufacturing'))){
            $features['manufacturing'] = [
                'name' => 'Manufacturing',
                'default' => false,
                'permission_ids' => '136,'
            ];
        }
        if(file_exists(base_path('Modules/Ecommerce'))) {
            $features['ecommerce'] = [
                'name' => 'Ecommerce',
                'default' => false,
                'permission_ids' => '136,'
            ];
        }
        if(file_exists(base_path('Modules/Woocommerce'))){
            $features['woocommerce'] = [
                'name' => 'Woocommerce',
                'default' => false,
                'permission_ids' => '136,'
            ];
        }
        if(file_exists(base_path('Modules/Restaurant'))){
            $features['restaurant'] = [
                'name' => 'Restaurant',
                'default' => false,
                'permission_ids' => '136,'
            ];
        }

        return $features;
    }

    //This function is called from tenantCheckout() in payment controller
    public function createTenant($request)
    {
        if (cache()->has('general_setting')) {
            $general_setting = cache()->get('general_setting');
        } else {
            $general_setting = DB::table('general_settings')->latest()->first();
        }
        $package = Package::select('is_free_trial', 'features')->find($request->package_id);
        $features = json_decode($package->features);
        $modules = [];
        if (in_array('manufacturing', $features)) {
            $modules[] = 'manufacturing';
        }
        if (in_array('ecommerce', $features)) {
            $modules[] = 'ecommerce';
        }
        if (in_array('woocommerce', $features))
            $modules[] = 'woocommerce';
        if (count($modules))
            $modules = implode(",", $modules);
        else
            $modules = Null;

        if ($package->is_free_trial)
            $numberOfDaysToExpired = $general_setting->free_trial_limit;
        elseif ($request->subscription_type == 'monthly')
            $numberOfDaysToExpired = 30;
        elseif ($request->subscription_type == 'yearly')
            $numberOfDaysToExpired = 365;
        if (isset($request->payment_method))
            $paid_by = $request->payment_method;
        else
            $paid_by = '';
        //creating tenant
        $tenant = Tenant::create(['id' => $request->tenant]);
        $tenant->domains()->create(['domain' => $request->tenant . '.' . env('CENTRAL_DOMAIN')]);

        if ($paid_by) {
            TenantPayment::create(['tenant_id' => $tenant->id, 'amount' => $request->price, 'paid_by' => $paid_by]);
        }

        ///////////////Start if someone wants ecommerce demo as his own demo////////////////
        // if (isset($modules) && str_contains($modules, "ecommerce") && file_exists(public_path('ecommerce_demo.sql'))) {
        //     $tenant->run(function () {
        //         DB::unprepared(file_get_contents(public_path('ecommerce_demo.sql')));
        //     });
        // }
        ///////////////End if someone wants ecommerce demo as his own demo////////////////

        // Start set tenant specific data for tenant seeder
        $packageData = Package::find($request->package_id);
        $pack_perm_role_pairs = explode('),(', trim($packageData->role_permission_values, '()'));
        // Convert each pair into an associative array
        if ($pack_perm_role_pairs != [""]) {
            $package_permissions_role = array_map(function ($pk_perm_role_p) {
                [$permission_id, $role_id] = explode(',', $pk_perm_role_p); // Split the pair
                return [
                    'permission_id' => (int) $permission_id, // Cast to int
                    'role_id' => (int) $role_id,             // Cast to int
                ];
            }, $pack_perm_role_pairs);
        } else {
            $package_permissions_role = [];
        }

        $tenantData = [
            //set general_setting information
            'site_title' => $general_setting->site_title,
            'site_logo' => $general_setting->site_logo,
            'package_id' => $request->package_id,
            'subscription_type' => $request->subscription_type,
            'developed_by' => $general_setting->developed_by,
            'modules' => $modules,
            'expiry_date' => date("Y-m-d", strtotime("+" . $numberOfDaysToExpired . " days")),
            //set user information
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'phone' => $request->phone_number,
            'company_name' => $request->company_name,
            //set permission info
            'package_permissions_role' => $package_permissions_role,
        ];
        //End set tenant specific data for TenantDatabaseSeeder and call running TenantDatabaseSeeder

        //Start running TenantDatabaseSeeder
        TenantDatabaseSeeder::$tenantData = $tenantData;
        Artisan::call('tenants:seed', [
            '--tenants' => $request->tenant,
            '--force' => true,
        ]);
        //End running TenantDatabaseSeeder

        copy(public_path("landlord/images/logo/") . $general_setting->site_logo, public_path("logo/") . $general_setting->site_logo);

        //Start running Ecommerce seeder for tenant if package has ecommerce module
        if (isset($modules) && str_contains($modules, 'ecommerce')) {
            Artisan::call('tenants:seed', [
                '--tenants' => $request->tenant,
                '--class' => EcommerceDatabaseSeeder::class,
                '--force' => true,
            ]);

            //Update slug column on category,brand,product table as this is needed for ecommerce
            $tenant->run(function () {
                $this->brandSlug();
                $this->categorySlug();
                $this->productSlug();

                DB::table('categories')->whereIn('id', [1, 6, 12, 23, 29, 30, 31, 33, 39])->update([
                    'icon' => DB::raw("
                                    CASE
                                        WHEN id = 1 THEN '20240117121500.png'
                                        WHEN id = 6 THEN '20240117121330.png'
                                        WHEN id = 12 THEN '20240117121400.png'
                                        WHEN id = 23 THEN '20240117121523.png'
                                        WHEN id = 29 THEN '20240117121304.png'
                                        WHEN id = 30 THEN '20240117121238.png'
                                        WHEN id = 31 THEN '20240117122452.png'
                                        WHEN id = 33 THEN '20240117121224.png'
                                        WHEN id = 39 THEN '20240204050037.png'
                                    END
                                ")
                ]);

                DB::table('products')->update(['is_online' => 1]);
            });

            copy(public_path("logo/") . $general_setting->site_logo, public_path("frontend/images/") . $general_setting->site_logo);
        }
        //End running Ecommerce seeder if package has ecommerce module

        if (!env('WILDCARD_SUBDOMAIN')) {
            $this->addSubdomain($tenant);
        }

        //updating tenant others information on landlord DB
        $tenant->update(['package_id' => $request->package_id, 'subscription_type' => $request->subscription_type, 'company_name' => $request->company_name, 'phone_number' => $request->phone_number, 'email' => $request->email, 'expiry_date' => date("Y-m-d", strtotime("+" . $numberOfDaysToExpired . " days"))]);

        // $message have no use, it is not shown in any place, as frontend signup redirect to tenants domain,
        // check PaymentController@tenantCheckout function
        $message = 'Client created successfully.';

        //sending welcome message to tenant
        $mail_setting = MailSetting::latest()->first();
        if ($mail_setting) {
            $this->setMailInfo($mail_setting);
            $mail_data['email'] = $request->email;
            $mail_data['company_name'] = $request->company_name;
            $mail_data['superadmin_company_name'] = $general_setting->site_title;
            $mail_data['subdomain'] = $request->tenant;
            $mail_data['name'] = $request->name;
            $mail_data['password'] = $request->password;
            $mail_data['superadmin_email'] = $general_setting->email;
            try {
                Mail::to($mail_data['email'])->send(new TenantCreate($mail_data));
            } catch (\Exception $e) {
                $message = 'Client created successfully. Please setup your <a href="mail_setting">mail setting</a> to send mail.';
            }
        }
    }

    public function categorySlug()
    {
        Category::whereNull('slug')->each(function ($cat) {
            $cat->slug = Str::slug($cat->name, '-');
            $cat->save();
        });

        //Best approach for larger dataset
        // DB::table('categories')
        // ->whereNull('slug')
        // ->update(['slug' => DB::raw("REPLACE(LOWER(name), ' ', '-')")]);
    }

    public function brandSlug()
    {
        Brand::whereNull('slug')->each(function ($brand) {
            $brand->slug = Str::slug($brand->title, '-');
            $brand->save();
        });
    }

    public function productSlug()
    {
        Product::whereNull('slug')->each(function ($product) {
            $product->slug = Str::slug($product->name, '-');
            $product->save();
        });
    }

    public function changePermission($tenant, $abandoned_permission_ids, $permission_ids, int $package_id, ?string $modules = null, $expiry_date = null, $subscription_type = null)
    {
        $abandoned_permission_ids = json_decode($abandoned_permission_ids);
        $permission_ids = json_decode($permission_ids);
        $tenant->run(function () use ($tenant, $abandoned_permission_ids, $permission_ids, $package_id, $modules, $expiry_date, $subscription_type) {

            if (count($abandoned_permission_ids)) {
                DB::table('role_has_permissions')->whereIn('permission_id', $abandoned_permission_ids)->delete();
            }
            if (count($permission_ids)) {
                foreach ($permission_ids as $permission_id) {
                    if (!(DB::table('role_has_permissions')->where([['role_id', 1], ['permission_id', $permission_id]])->first())) {
                        DB::table('role_has_permissions')->insert(['role_id' => 1, 'permission_id' => $permission_id]);
                    }
                }
            }
            $general_setting = \App\Models\GeneralSetting::latest()->first();
            if ($expiry_date != null && $subscription_type != null) {
                $general_setting->update(['package_id' => $package_id, 'modules' => $modules, 'expiry_date' => $expiry_date, 'subscription_type' => $subscription_type]);
            } else {
                $general_setting->update(['package_id' => $package_id, 'modules' => $modules]);
            }

            Artisan::call('tenants:migrate', [
                '--tenants' => $tenant->id,
                '--force' => true,
            ]);

            Artisan::call('tenants:seed', [
                '--tenants' => $tenant->id,
                '--force' => true,
            ]);

            if (isset($modules) && str_contains($modules, 'ecommerce')) {
                Artisan::call('tenants:seed', [
                    '--tenants' => $tenant->id,
                    '--class' => EcommerceDatabaseSeeder::class,
                    '--force' => true,
                ]);

                $this->categorySlug();
                $this->brandSlug();
                $this->productSlug();

                copy(public_path("logo/") . $general_setting->site_logo, public_path("frontend/images/") . $general_setting->site_logo);
            }
        });
    }

    public function addSubdomain($tenant)
    {
        $subdomain = $tenant->id;
        if (env('SERVER_TYPE') == 'cpanel') {
            $url = "https://" . env('CENTRAL_DOMAIN') . ":2083/json-api/cpanel?cpanel_jsonapi_func=addsubdomain&cpanel_jsonapi_module=SubDomain&cpanel_jsonapi_version=2&domain=" . $subdomain . "&rootdomain=" . env('CENTRAL_DOMAIN');
            if (env('ROOT_DOMAIN'))
                $url .= "&dir=public_html";
            else
                $url .= "&dir=" . env('CENTRAL_DOMAIN');
            //return $url;
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            //setting the curl headers
            $headers = array(
                "Authorization: cpanel " . env('CPANEL_USER_NAME') . ":" . env('CPANEL_API_KEY'),
                "Content-Type: text/plain"
            );
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

            //for debug only!
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

            curl_exec($curl);
            curl_close($curl);
        } elseif (env('SERVER_TYPE') == 'plesk') {
            $host = env('CENTRAL_DOMAIN');
            $username = env('PLESK_USER_NAME');
            $password = env('PLESK_PASSWORD');
            $pleskApiUrl = 'https://' . $host . ':8443/api/v2/domains';
            $domainData = [
                'name' => $subdomain . '.' . $host,
                'hosting_type' => 'virtual',
                'hosting_settings' => [
                    'document_root' => '/httpdocs',
                ],
                'parent_domain' => [
                    "name" => $host,
                ]
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $pleskApiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($domainData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode("$username:$password"),
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Disable SSL hostname verification if needed
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL certificate verification if needed

            $response = curl_exec($ch);
            curl_close($ch);
            $response = json_decode($response);
            $tenant->setInternal('domain_id', $response->id);
        }
    }

    public function deleteSubdomain($tenant)
    {
        if (env('SERVER_TYPE') == 'cpanel') {
            $subdomain = $tenant->id;
            $url = "https://" . env('CENTRAL_DOMAIN') . ":2083/json-api/cpanel?cpanel_jsonapi_func=delsubdomain&cpanel_jsonapi_module=SubDomain&cpanel_jsonapi_version=2&domain=" . $subdomain . "." . env('CENTRAL_DOMAIN');
            //return $url;
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            //setting the curl headers
            $headers = array(
                "Authorization: cpanel " . env('CPANEL_USER_NAME') . ":" . env('CPANEL_API_KEY'),
                "Content-Type: text/plain"
            );
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

            //for debug only!
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

            $resp = curl_exec($curl);
            curl_close($curl);
        } elseif (env('SERVER_TYPE') == 'plesk') {
            $host = env('CENTRAL_DOMAIN');
            $username = env('PLESK_USER_NAME');
            $password = env('PLESK_PASSWORD');
            $domain_id = $tenant->getInternal('domain_id');
            $pleskApiUrl = 'https://' . $host . ':8443/api/v2/domains/' . $domain_id;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $pleskApiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode("$username:$password"),
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Disable SSL hostname verification if needed
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL certificate verification if needed
            curl_exec($ch);
            curl_close($ch);
        }
    }
}
