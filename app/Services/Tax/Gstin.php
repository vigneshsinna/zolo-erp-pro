<?php

namespace App\Services\Tax;

use Illuminate\Validation\ValidationException;

final class Gstin
{
    public static function normalize(mixed $value): string
    {
        $value = is_string($value) ? strtoupper(trim($value)) : '';
        if (!preg_match('/^(0[1-9]|[12][0-9]|3[0-8])[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/D', $value)) {
            throw ValidationException::withMessages(['gstin' => 'Enter a valid 15-character GSTIN. Format does not verify registration.']);
        }
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'; $sum = 0; $factor = 2;
        for ($i = 13; $i >= 0; $i--) {
            $n = strpos($alphabet, $value[$i]) * $factor;
            $sum += intdiv($n, 36) + $n % 36; $factor = $factor === 2 ? 1 : 2;
        }
        if ($alphabet[(36 - $sum % 36) % 36] !== $value[14]) {
            throw ValidationException::withMessages(['gstin' => 'GSTIN checksum is invalid.']);
        }
        return $value;
    }

    /**
     * Validation rule for a party GSTIN field. Under GST an invalid GSTIN would silently make the party
     * unregistered (no tax on its bills), so it is refused at entry; other tax setups keep a free VAT number.
     */
    public static function rule(): array
    {
        if (!GstSetupService::usesGst()) return ['nullable', 'string', 'max:50'];
        return ['nullable', 'string', function ($attribute, $value, $fail) {
            try { self::normalize($value); } catch (ValidationException $e) { $fail(collect($e->errors())->flatten()->first()); }
        }];
    }

    public static array $states = [
        '01' => 'Jammu and Kashmir',
        '02' => 'Himachal Pradesh',
        '03' => 'Punjab',
        '04' => 'Chandigarh',
        '05' => 'Uttarakhand',
        '06' => 'Haryana',
        '07' => 'Delhi',
        '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh',
        '10' => 'Bihar',
        '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland',
        '14' => 'Manipur',
        '15' => 'Mizoram',
        '16' => 'Tripura',
        '17' => 'Meghalaya',
        '18' => 'Assam',
        '19' => 'West Bengal',
        '20' => 'Jharkhand',
        '21' => 'Odisha',
        '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh',
        '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu',
        '27' => 'Maharashtra',
        '28' => 'Andhra Pradesh (Old)',
        '29' => 'Karnataka',
        '30' => 'Goa',
        '31' => 'Lakshadweep',
        '32' => 'Kerala',
        '33' => 'Tamil Nadu',
        '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana',
        '37' => 'Andhra Pradesh',
        '38' => 'Ladakh',
        '97' => 'Other Territory',
    ];

    public static array $constitutions = [
        'P' => 'Proprietorship / Individual',
        'C' => 'Company / Corporation',
        'F' => 'Partnership / LLP',
        'H' => 'Hindu Undivided Family (HUF)',
        'A' => 'Association of Persons (AOP)',
        'B' => 'Body of Individuals (BOI)',
        'G' => 'Government Agency',
        'J' => 'Artificial Juridical Person',
        'L' => 'Local Authority',
        'T' => 'Trust',
    ];

    public static function parse(mixed $value): array
    {
        $gstin = is_string($value) ? strtoupper(trim($value)) : '';
        $stateCode = strlen($gstin) >= 2 ? substr($gstin, 0, 2) : '';
        $stateName = self::$states[$stateCode] ?? ($stateCode ? "State $stateCode" : '');
        $pan = strlen($gstin) >= 12 ? substr($gstin, 2, 10) : '';
        $panType = strlen($pan) >= 4 ? $pan[3] : '';
        $constitution = self::$constitutions[$panType] ?? ($panType ? "Constitution ($panType)" : '');

        $isValid = false;
        $error = null;
        try {
            self::normalize($gstin);
            $isValid = true;
        } catch (\Throwable $e) {
            $isValid = false;
            $error = $e->getMessage();
        }

        return [
            'gstin' => $gstin,
            'state_code' => $stateCode,
            'state_name' => $stateName,
            'pan' => $pan,
            'pan_type' => $panType,
            'constitution' => $constitution,
            'is_valid' => $isValid,
            'error' => $error,
        ];
    }

    public static function state(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(0[1-9]|[12][0-9]|3[0-8])$/D', $value)) {
            throw ValidationException::withMessages(['state_code' => 'Select a valid Indian state or union territory code.']);
        }
        return $value;
    }
}
