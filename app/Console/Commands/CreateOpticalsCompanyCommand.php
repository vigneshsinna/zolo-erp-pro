<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\Industry\IndustryProfileService;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyDirectoryService;
use Database\Seeders\OpticalsMasterSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateOpticalsCompanyCommand extends Command
{
    protected $signature = 'erp:create-opticals-company
                            {--name=LenzBreeze Optical Lab : Trade name of the company}
                            {--legal-name=LenzBreeze Optical Lab Private Limited : Legal name of the company}
                            {--code=LENZ : Company code}
                            {--admin=1 : Admin User ID}';

    protected $description = 'Create or initialize a Lens Manufacturer / Opticals vertical company profile';

    public function handle(CompanyDirectoryService $directory, IndustryProfileService $profiles): int
    {
        $code = strtoupper(trim($this->option('code')));
        $adminId = (int) $this->option('admin');
        $user = User::find($adminId) ?? User::first();

        if (!$user) {
            $this->error('No administrative user found to own the company.');
            return self::FAILURE;
        }

        $company = Company::where('code', $code)->first();

        if ($company) {
            $this->info("Company with code [{$code}] already exists (ID: {$company->id}). Configuring opticals profile...");
        } else {
            $this->info("Creating new Lens Manufacturer company: {$this->option('name')} [{$code}]...");
            $today = now();
            $fyStart = $today->month >= 4 ? $today->year . '-04-01' : ($today->year - 1) . '-04-01';
            $fyEnd = $today->month >= 4 ? ($today->year + 1) . '-03-31' : $today->year . '-03-31';

            $company = $directory->createCompany($user->id, [
                'legal_name' => $this->option('legal-name'),
                'trade_name' => $this->option('name'),
                'code' => $code,
                'industry' => 'opticals',
                'subtype' => 'lens_manufacturing',
                'state_code' => '33', // Tamil Nadu
                'timezone' => 'Asia/Kolkata',
                'fy_start' => $fyStart,
                'fy_end' => $fyEnd,
                'address' => '42, Industrial Estate, Lens Tech Park',
                'city' => 'Chennai',
                'postal_code' => '600032',
                'phone' => '+91 98765 43210',
                'email' => 'lab@lenzbreeze.com',
                'website' => 'https://lenzbreeze.com',
                'pan' => 'AABCL1234F',
                'gstin' => '33AABCL1234F1Z5',
                'registration_type' => 'regular',
            ]);
        }

        // Apply opticals profile
        $branch = $company->branches()->orderBy('id')->first();
        $fy = $company->fiscalYears()->orderBy('start_date')->first();

        if ($branch && $fy) {
            $context = new CompanyContext($company->id, $branch->id, $fy->id);
            $profiles->apply('opticals', 'lens_manufacturing', $context, $user->id);
        }

        // Seed opticals masters (Units, Brands, Categories, Sale Types, Products)
        (new OpticalsMasterSeeder)->seedCompany($company);

        // Ensure AI Copilot is provisioned for this company according to installation policy
        if (\Illuminate\Support\Facades\Schema::hasTable('ai_feature_settings')) {
            app(\App\Ai\Services\AiFeatureGate::class)->provisionCompanyDefaults($company->id, $user->id);
        }

        $this->info("Lens Manufacturer company [{$company->trade_name}] is ready!");
        $this->table(['Key', 'Value'], [
            ['Company ID', $company->id],
            ['Company Code', $company->code],
            ['Trade Name', $company->trade_name],
            ['Legal Name', $company->legal_name],
            ['Industry', 'Opticals & Eyewear (opticals)'],
            ['Subtype', 'Lens Manufacturer / Rx Lab (lens_manufacturing)'],
            ['Units Seeded', 'PRS (Pairs), PCS (Pieces), BOX (Boxes)'],
            ['Sale Types', '12%-GST (Optical Lenses), 18%-GST (Frames & Fitting)'],
            ['Voucher Support', 'Lab Receipt (Thermal 100mm), Packing Slip (A6/Multi-order)'],
        ]);

        return self::SUCCESS;
    }
}

