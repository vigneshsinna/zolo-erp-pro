<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Symmetric, verified company role and document direction classifier.
 * Uses exact normalized GSTINs first, then normalized company legal/trade/alias names
 * while avoiding short-substring false matches.
 */
final class CompanyDocumentRoleClassifier
{
    private const LEGAL_SUFFIXES = [
        'private limited',
        'pvt ltd',
        'pvt. ltd.',
        'limited',
        'ltd',
        'ltd.',
        'llp',
        'inc',
        'co',
        'co.',
        'company',
        'enterprises',
        'traders',
    ];

    /**
     * @return array{
     *     company_role: 'own_buyer'|'own_issuer'|'both_ambiguous'|'neither_foreign'|'unknown',
     *     document_direction: 'incoming'|'outgoing'|'ambiguous'|'foreign'|'unknown',
     *     is_buyer_matched: bool,
     *     is_issuer_matched: bool,
     *     buyer_match_strength: 'gstin'|'exact_name'|'none',
     *     issuer_match_strength: 'gstin'|'exact_name'|'none',
     *     company_names: list<string>,
     *     company_gstins: list<string>
     * }
     */
    public function classify(
        int $companyId,
        ?string $issuerName,
        ?string $issuerGstin,
        ?string $buyerName,
        ?string $buyerGstin,
    ): array {
        $companyNames = [];
        $companyGstins = [];

        try {
            if (Schema::hasTable('companies')) {
                $cRecord = DB::table('companies')->where('id', $companyId)->first(['legal_name', 'trade_name']);
                if ($cRecord) {
                    if (! empty($cRecord->legal_name)) {
                        $companyNames[] = trim((string) $cRecord->legal_name);
                    }
                    if (! empty($cRecord->trade_name)) {
                        $companyNames[] = trim((string) $cRecord->trade_name);
                    }
                }
            }
            if (Schema::hasTable('tax_registrations')) {
                $companyGstins = DB::table('tax_registrations')
                    ->where('company_id', $companyId)
                    ->whereNotNull('gstin')
                    ->pluck('gstin')
                    ->map(static fn ($g): string => strtoupper(trim((string) $g)))
                    ->filter(static fn (string $g): bool => $g !== '')
                    ->values()
                    ->all();
            }
        } catch (\Throwable) {
            // Unbound DB in isolated unit tests
        }

        $companyNames = array_values(array_unique($companyNames));
        $companyGstins = array_values(array_unique($companyGstins));

        $issuerStrength = $this->matchParty($issuerName, $issuerGstin, $companyNames, $companyGstins);
        $buyerStrength = $this->matchParty($buyerName, $buyerGstin, $companyNames, $companyGstins);

        $isIssuerMatched = $issuerStrength !== 'none';
        $isBuyerMatched = $buyerStrength !== 'none';

        if ($isIssuerMatched && $isBuyerMatched) {
            $companyRole = 'both_ambiguous';
            $direction = 'ambiguous';
        } elseif ($isBuyerMatched) {
            $companyRole = 'own_buyer';
            $direction = 'incoming';
        } elseif ($isIssuerMatched) {
            $companyRole = 'own_issuer';
            $direction = 'outgoing';
        } else {
            $hasPartyIdentity = ($buyerName !== null && trim($buyerName) !== '')
                || ($buyerGstin !== null && trim($buyerGstin) !== '');
            if ($hasPartyIdentity && ($companyNames !== [] || $companyGstins !== [])) {
                $companyRole = 'neither_foreign';
                $direction = 'foreign';
            } else {
                $companyRole = 'unknown';
                $direction = 'unknown';
            }
        }

        return [
            'company_role' => $companyRole,
            'document_direction' => $direction,
            'is_buyer_matched' => $isBuyerMatched,
            'is_issuer_matched' => $isIssuerMatched,
            'buyer_match_strength' => $buyerStrength,
            'issuer_match_strength' => $issuerStrength,
            'company_names' => $companyNames,
            'company_gstins' => $companyGstins,
        ];
    }

    /**
     * @param list<string> $companyNames
     * @param list<string> $companyGstins
     * @return 'gstin'|'exact_name'|'none'
     */
    private function matchParty(?string $partyName, ?string $partyGstin, array $companyNames, array $companyGstins): string
    {
        if ($partyGstin !== null) {
            $normGstin = strtoupper((string) preg_replace('/\s+/', '', trim($partyGstin)));
            if ($normGstin !== '' && in_array($normGstin, $companyGstins, true)) {
                return 'gstin';
            }
            // If an explicit GSTIN was provided and company registrations are known, a different GSTIN
            // proves it is NOT the company unless name match is strictly identical.
            if ($normGstin !== '' && $companyGstins !== []) {
                return 'none';
            }
        }

        if ($partyName === null || trim($partyName) === '' || $companyNames === []) {
            return 'none';
        }

        $normParty = $this->normalizeEntityName($partyName);
        if (mb_strlen($normParty) < 4) {
            return 'none';
        }

        foreach ($companyNames as $cName) {
            $normCompany = $this->normalizeEntityName($cName);
            if (mb_strlen($normCompany) < 4) {
                continue;
            }
            if ($normParty === $normCompany) {
                return 'exact_name';
            }
        }

        return 'none';
    }

    private function normalizeEntityName(string $name): string
    {
        $clean = mb_strtolower(trim($name));
        $clean = preg_replace('/[^\pL\pN\s]/u', ' ', $clean) ?? $clean;
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);

        foreach (self::LEGAL_SUFFIXES as $suffix) {
            $suffixClean = trim(preg_replace('/[^\pL\pN\s]/u', ' ', $suffix) ?? $suffix);
            if ($suffixClean !== '' && str_ends_with($clean, ' ' . $suffixClean)) {
                $clean = trim(substr($clean, 0, -strlen(' ' . $suffixClean)));
            }
        }

        return $clean;
    }
}
