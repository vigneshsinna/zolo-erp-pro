<?php

declare(strict_types=1);

namespace App\Services\Optical;

use Illuminate\Validation\ValidationException;

/**
 * Deterministic optical lens specification normalizer and canonical signature generator.
 * Distinguishes missing (null) from zero (0.00 / PL), preserves explicit +/- sign,
 * never equates 0 and 180 axis, and never performs float equality or clinical recommendations.
 */
final class OpticalSpecNormalizer
{
    public const IDENTITY_FIELDS = [
        'lens_family',
        'lens_material',
        'refractive_index',
        'coating',
        'lens_form',
        'sph',
        'cyl',
        'axis',
        'addition',
        'base_curve',
        'dia',
        'lr',
    ];

    public const VALID_LENS_FORMS = [
        'finished_stock',
        'semi_finished_blank',
        'custom_rx',
        'service',
    ];

    public static function normalizePower(mixed $raw, string $field = 'sph'): ?string
    {
        if ($raw === null) {
            return null;
        }
        $str = trim((string) $raw);
        if ($str === '') {
            return null;
        }
        if (preg_match('/^(?:pl|plano)$/i', $str)) {
            return '0.00';
        }
        if (preg_match('/[eE]/', $str) || !preg_match('/^([+-])?(\d{1,3})(?:\.(\d{1,4}))?$/D', $str, $m)) {
            throw ValidationException::withMessages([
                $field => "Optical {$field} must be a valid decimal power (e.g. -2.00, +1.50, 0.00, or PL).",
            ]);
        }

        $sign = $m[1] === '-' ? '-' : ($m[1] === '+' ? '+' : '');
        $whole = (int) $m[2];
        $fracRaw = str_pad(substr($m[3] ?? '0', 0, 4), 4, '0');
        $scaled = $whole * 10000 + (int) $fracRaw;
        // Round to 2 decimals via integer arithmetic (10000 -> 100)
        $roundedCents = (int) intdiv($scaled + 50, 100);
        if ($roundedCents > 5000) {
            throw ValidationException::withMessages([
                $field => "Optical {$field} is outside supported range.",
            ]);
        }
        if ($roundedCents === 0) {
            return '0.00';
        }

        $effectiveSign = $sign === '-' ? '-' : '+';
        return sprintf('%s%d.%02d', $effectiveSign, intdiv($roundedCents, 100), $roundedCents % 100);
    }

    public static function normalizeAxis(mixed $raw, string $field = 'axis'): ?string
    {
        if ($raw === null) {
            return null;
        }
        $str = trim((string) $raw);
        if ($str === '') {
            return null;
        }
        if (!preg_match('/^\d{1,3}$/D', $str)) {
            throw ValidationException::withMessages([$field => 'Axis must be an integer between 0 and 180.']);
        }
        $axis = (int) $str;
        if ($axis < 0 || $axis > 180) {
            throw ValidationException::withMessages([$field => 'Axis must be between 0 and 180 degrees.']);
        }

        return (string) $axis;
    }

    public static function normalizeEye(mixed $raw, string $field = 'lr'): ?string
    {
        if ($raw === null) {
            return null;
        }
        $str = strtoupper(trim((string) $raw));
        if ($str === '') {
            return null;
        }

        return match ($str) {
            'R', 'RE', 'OD', 'RIGHT' => 'R',
            'L', 'LE', 'OS', 'LEFT' => 'L',
            'PAIR', 'OU', 'BOTH', 'B' => 'Pair',
            default => throw ValidationException::withMessages([$field => 'Eye designation must be R, L, or Pair.']),
        };
    }

    public static function normalizeDia(mixed $raw, string $field = 'dia'): ?string
    {
        if ($raw === null) {
            return null;
        }
        $str = trim((string) $raw);
        if ($str === '') {
            return null;
        }
        if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $str)) {
            throw ValidationException::withMessages([$field => 'Diameter must be a valid positive number (e.g. 65, 70, 75).']);
        }
        $num = (float) $str;
        if ($num <= 0 || $num > 150) {
            throw ValidationException::withMessages([$field => 'Diameter must be between 1 and 150 mm.']);
        }

        return rtrim(rtrim(sprintf('%.2f', $num), '0'), '.');
    }

    public static function normalizeTextAttr(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $str = trim((string) $raw);
        return $str === '' ? null : $str;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{canonical: array<string, ?string>, raw: array<string, mixed>, signature: string}
     */
    public static function normalizeSpec(array $raw, bool $requireClinicalAxis = false): array
    {
        $lensForm = self::normalizeTextAttr($raw['lens_form'] ?? null) ?? 'finished_stock';
        if (!in_array($lensForm, self::VALID_LENS_FORMS, true)) {
            throw ValidationException::withMessages(['lens_form' => 'Unsupported optical lens form.']);
        }

        $canonical = [
            'lens_family' => self::normalizeTextAttr($raw['lens_family'] ?? null),
            'lens_material' => self::normalizeTextAttr($raw['lens_material'] ?? null),
            'refractive_index' => self::normalizeTextAttr($raw['refractive_index'] ?? null),
            'coating' => self::normalizeTextAttr($raw['coating'] ?? null),
            'lens_form' => $lensForm,
            'sph' => self::normalizePower($raw['sph'] ?? null, 'sph'),
            'cyl' => self::normalizePower($raw['cyl'] ?? null, 'cyl'),
            'axis' => self::normalizeAxis($raw['axis'] ?? null, 'axis'),
            'addition' => self::normalizePower($raw['addition'] ?? null, 'addition'),
            'base_curve' => self::normalizePower($raw['base_curve'] ?? ($raw['base'] ?? null), 'base_curve'),
            'dia' => self::normalizeDia($raw['dia'] ?? null, 'dia'),
            'lr' => self::normalizeEye($raw['lr'] ?? null, 'lr'),
        ];

        if ($requireClinicalAxis && $canonical['cyl'] !== null && $canonical['cyl'] !== '0.00' && $canonical['axis'] === null) {
            throw ValidationException::withMessages([
                'axis' => 'Axis is required when cylinder power is non-zero; do not fabricate or guess axis.',
            ]);
        }

        return [
            'canonical' => $canonical,
            'raw' => $raw,
            'signature' => self::computeSignature($canonical),
        ];
    }

    /**
     * @param array<string, ?string> $canonical
     */
    public static function computeSignature(array $canonical): string
    {
        $ordered = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            $val = $canonical[$field] ?? null;
            $ordered[$field] = $val === null ? null : mb_strtolower(trim((string) $val));
        }

        return hash('sha256', json_encode($ordered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Compare requested spec against a candidate spec and return differing fields.
     *
     * @param array<string, ?string> $requested
     * @param array<string, ?string> $candidate
     * @return array<string, array{requested: ?string, candidate: ?string}>
     */
    public static function diffFields(array $requested, array $candidate): array
    {
        $diffs = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            $reqVal = $requested[$field] ?? null;
            $candVal = $candidate[$field] ?? null;
            if ($reqVal !== null && $reqVal !== $candVal) {
                $diffs[$field] = ['requested' => $reqVal, 'candidate' => $candVal];
            }
        }

        return $diffs;
    }
}
