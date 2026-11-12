<?php

namespace Database\Seeders;

use App\Models\ProductType;
use Illuminate\Database\Seeder;

class ProductTypeSeeder extends Seeder
{
    /**
     * Optical lens design types matching LenzBreeze ophthalmic lab catalog.
     */
    public const OPTICAL_TYPES = [
        // Progressive PAL Designs
        'Progressive',
        'Progressive-Xtra Short',
        'Progressive HD Short',
        'Progressive PG',
        'Progressive-HC',
        'Progressive-HMC',
        'Progressive-HMC-BRC',
        'Progressive-BRC-Blue',
        'Progressive-HMC-PG',
        'Progressive-HMC-BRC-PG',
        'Progressive-HMC-PG-Blue',
        'Progressive-HMC-POLY',
        'Progressive-HMC-POLY-PG',

        // Bifocals & Kryptok
        'D-Bifocal',
        'D-Bifocal - PG',
        'Kryptok Bifocal',
        'Kryptok Bifocal-PG',

        // Single Vision
        'Single Vision',
        'Single Vision PG',
        'SV-HC-SPH',
        'SV-HC-CYL',
        'SV-COM',
        'SV-ARC',
        'SV-ARC-BC',
        'SV-ARC-BC-Blue',
        'SV-PG-HC',
        'SV-PG-ARC',
        'SV-PG-ARC-BC',
        'SV-POLY-BC',
        'SV-PG-POLY-BC',

        // KBF Series (Kryptok Bifocal Coatings & Materials)
        'KBF-HC',
        'KBF-HMC',
        'KBF-HC-PG',
        'KBF-HMC-PG',
        'KBF-HMC-BRC',
        'KBF-BRC-Blue',
        'KBF-BRC-PG',
        'KBF-BRC-PG-Blue',
        'KBF-POLY-BRC',
        'KBF-POLY-BRC-PG',

        // KBF Base Curves & Axis Orientations
        'KBF-3C-90°',
        'KBF-3C-HMC-90°',
        'KBF-3C-HC-PG-90°',
        'KBF-3C-HMC-PG-90°',
        'KBF-3C-180°',
        'KBF-3C-HMC-180°',
        'KBF-3C-HC-PG-180°',
        'KBF-3C-HMC-PG-180°',
        'KBF-4C-90°',
        'KBF-4C-45°',
        'KBF-4C-135°',
        'KBF-4C-180°',
        'KBF-Minus/Plus',

        // PRO Series (Progressive Curvature & Axis Orientations)
        'PRO-3C-180°',
        'PRO-3C-90°',
        'PRO-3C-HC-PG-180°',
        'PRO-3C-HC-PG-90°',
        'PRO-3C-HMC-180°',
        'PRO-3C-HMC-90°',
        'PRO-3C-HMC-PG-180°',
        'PRO-3C-HMC-PG-90°',
        'PRO-4C-135°',
        'PRO-4C-180°',
        'PRO-4C-45°',
        'PRO-4C-90°',
        'PRO-BRC-Blue',
        'PRO-BRC-PG',
        'PRO-BRC-PG-Blue',
        'PRO-HC',
        'PRO-HMC',
        'PRO-HC-PG',
        'PRO-HMC-BRC',
        'PRO-HMC-PG',
        'PRO-Minus/Plus',
        'PRO-POLY-BRC',
        'PRO-POLY-BRC-PG',

        // Specialized KBF Blanks
        'KBF-HMC-BRC-PG',
        'KBF-HMC-PG-Blue',
        'KBF-HMC-POLY',
        'KBF-HMC-POLY-PG',
        'KBF-HMC-DBF',

        // General / Miscellaneous Lab Types
        'DUP',
        'Others',
    ];

    public function run(?int $companyId = null): void
    {
        foreach (self::OPTICAL_TYPES as $typeName) {
            ProductType::firstOrCreate(
                [
                    'name' => $typeName,
                    'company_id' => $companyId,
                ],
                [
                    'is_active' => true,
                ]
            );
        }
    }
}

