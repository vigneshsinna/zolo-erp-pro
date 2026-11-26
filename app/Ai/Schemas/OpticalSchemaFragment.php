<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\Knowledge\IndustryContextResolver;
use App\Services\Optical\OpticalSpecNormalizer;
use App\Services\Platform\CompanyContext;
use Illuminate\Validation\ValidationException;

/**
 * Shared optical prescription, lens identity, and commercial header rules/requirements.
 * Enforces profile isolation so non-optical tenants fail closed on optical-only fields.
 */
final class OpticalSchemaFragment
{
    public const LINE_FIELDS = [
        'sph',
        'cyl',
        'axis',
        'base',
        'addition',
        'lr',
        'dia',
        'prescription_json',
        'optical_spec',
    ];

    public const SALES_HEADER_FIELDS = [
        'customer_order_no',
        'fitting_charge',
        'tinting_cost',
        'end_user_name',
        'end_user_phone',
        'end_user_email',
        'end_user_address',
    ];

    /** @return array<string, array<int, string>> */
    public static function lineRules(string $prefix = 'items.*'): array
    {
        return [
            "{$prefix}.client_line_id" => ['nullable', 'string', 'max:64'],
            "{$prefix}.sph" => ['nullable', 'string', 'max:16'],
            "{$prefix}.cyl" => ['nullable', 'string', 'max:16'],
            "{$prefix}.axis" => ['nullable', 'integer', 'min:0', 'max:180'],
            "{$prefix}.base" => ['nullable', 'string', 'max:20'],
            "{$prefix}.addition" => ['nullable', 'string', 'max:16'],
            "{$prefix}.lr" => ['nullable', 'string', 'in:R,L,Pair,RE,LE,OU,OD,OS,Right,Left,Both,r,l,pair'],
            "{$prefix}.dia" => ['nullable', 'string', 'max:16'],
            "{$prefix}.prescription_json" => ['nullable', 'array'],
            "{$prefix}.optical_spec" => ['nullable', 'array'],
            "{$prefix}.optical_spec.lens_family" => ['nullable', 'string', 'max:120'],
            "{$prefix}.optical_spec.lens_material" => ['nullable', 'string', 'max:80'],
            "{$prefix}.optical_spec.refractive_index" => ['nullable', 'string', 'max:20'],
            "{$prefix}.optical_spec.coating" => ['nullable', 'string', 'max:80'],
            "{$prefix}.optical_spec.lens_form" => ['nullable', 'string', 'in:finished_stock,semi_finished_blank,custom_rx,service'],
            "{$prefix}.optical_spec.sph" => ['nullable', 'string', 'max:16'],
            "{$prefix}.optical_spec.cyl" => ['nullable', 'string', 'max:16'],
            "{$prefix}.optical_spec.axis" => ['nullable', 'integer', 'min:0', 'max:180'],
            "{$prefix}.optical_spec.addition" => ['nullable', 'string', 'max:16'],
            "{$prefix}.optical_spec.base_curve" => ['nullable', 'string', 'max:16'],
            "{$prefix}.optical_spec.dia" => ['nullable', 'string', 'max:16'],
            "{$prefix}.optical_spec.lr" => ['nullable', 'string', 'max:16'],
        ];
    }

    /** @return array<string, array<int, string>> */
    public static function salesHeaderRules(): array
    {
        return [
            'customer_order_no' => ['nullable', 'string', 'max:191'],
            'fitting_charge' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'tinting_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'end_user_name' => ['nullable', 'string', 'max:191'],
            'end_user_phone' => ['nullable', 'string', 'max:50'],
            'end_user_email' => ['nullable', 'email', 'max:191'],
            'end_user_address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, array<int, string>> */
    public static function purchaseRouteRules(): array
    {
        return [
            'receipt_status' => ['nullable', 'string', 'in:received,not_received,pending_grn'],
        ];
    }

    /** @return list<ActionFieldRequirement> */
    public static function lineRequirements(): array
    {
        return [
            new ActionFieldRequirement('sph', 'Sphere (SPH)', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('cyl', 'Cylinder (CYL)', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('axis', 'Axis', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('base', 'Base Curve', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('addition', 'Addition (ADD)', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('lr', 'Eye (R/L/Pair)', 'string', inputControl: 'select', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('dia', 'Diameter (mm)', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('optical_spec', 'Optical Spec', 'array', inputControl: 'json', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('prescription_json', 'Prescription Metadata', 'array', inputControl: 'json', requiredWhen: fn (): bool => false),
        ];
    }

    /** @return list<ActionFieldRequirement> */
    public static function salesHeaderRequirements(): array
    {
        return [
            new ActionFieldRequirement('customer_order_no', 'Customer Order Reference', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('fitting_charge', 'Fitting Charge', 'decimal_string', inputControl: 'number', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('tinting_cost', 'Tinting Cost', 'decimal_string', inputControl: 'number', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('end_user_name', 'Wearer Name (Optional)', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('end_user_phone', 'Wearer Phone (Optional)', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('end_user_email', 'Wearer Email (Optional)', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('end_user_address', 'Wearer Address (Optional)', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
        ];
    }

    public static function assertAllowedForProfile(CompanyContext $context, array $input): void
    {
        $hasOptical = false;
        foreach (self::SALES_HEADER_FIELDS as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '' && $input[$field] !== 0 && $input[$field] !== '0' && $input[$field] !== '0.00') {
                $hasOptical = true;
                break;
            }
        }
        if (!$hasOptical && !empty($input['items']) && is_array($input['items'])) {
            foreach ($input['items'] as $line) {
                if (!is_array($line)) {
                    continue;
                }
                foreach (self::LINE_FIELDS as $field) {
                    if (array_key_exists($field, $line) && $line[$field] !== null && $line[$field] !== '' && $line[$field] !== []) {
                        $hasOptical = true;
                        break 2;
                    }
                }
            }
        }

        if ($hasOptical && (!config('ai_business_knowledge.opticals_enabled', false) || !app(IndustryContextResolver::class)->isOptical($context))) {
            throw ValidationException::withMessages([
                'optical' => 'Optical prescription and lens fields are only permitted when Opticals AI is enabled for an Opticals company profile.',
            ]);
        }
    }

    /**
     * Extract and normalize optical line fields, returning only fields explicitly supplied.
     *
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    public static function normalizeLineOpticalFields(array $line): array
    {
        $out = [];
        if (isset($line['sph']) && $line['sph'] !== null && trim((string) $line['sph']) !== '') {
            $out['sph'] = OpticalSpecNormalizer::normalizePower($line['sph'], 'sph');
        }
        if (isset($line['cyl']) && $line['cyl'] !== null && trim((string) $line['cyl']) !== '') {
            $out['cyl'] = OpticalSpecNormalizer::normalizePower($line['cyl'], 'cyl');
        }
        if (isset($line['axis']) && $line['axis'] !== null && trim((string) $line['axis']) !== '') {
            $out['axis'] = OpticalSpecNormalizer::normalizeAxis($line['axis'], 'axis');
        }
        if (isset($line['addition']) && $line['addition'] !== null && trim((string) $line['addition']) !== '') {
            $out['addition'] = OpticalSpecNormalizer::normalizePower($line['addition'], 'addition');
        }
        if (isset($line['base']) && $line['base'] !== null && trim((string) $line['base']) !== '') {
            $out['base'] = OpticalSpecNormalizer::normalizePower($line['base'], 'base');
        }
        if (isset($line['lr']) && $line['lr'] !== null && trim((string) $line['lr']) !== '') {
            $out['lr'] = OpticalSpecNormalizer::normalizeEye($line['lr'], 'lr');
        }
        if (isset($line['dia']) && $line['dia'] !== null && trim((string) $line['dia']) !== '') {
            $out['dia'] = OpticalSpecNormalizer::normalizeDia($line['dia'], 'dia');
        }
        if (!empty($line['prescription_json']) && is_array($line['prescription_json'])) {
            $out['prescription_json'] = $line['prescription_json'];
        }
        if (!empty($line['optical_spec']) && is_array($line['optical_spec'])) {
            $normalizedSpec = OpticalSpecNormalizer::normalizeSpec($line['optical_spec']);
            $out['optical_spec'] = array_filter($normalizedSpec['canonical'], static fn ($v) => $v !== null) + [
                'spec_signature' => $normalizedSpec['signature'],
            ];
        }

        return $out;
    }
}
