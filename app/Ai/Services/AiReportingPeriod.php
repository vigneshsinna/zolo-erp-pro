<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use Illuminate\Validation\ValidationException;

final class AiReportingPeriod
{
    /** @return array{from: string, to: string, timezone: string} */
    public static function resolve(AiPrincipal $principal, ?string $from, ?string $to, bool $monthToDate = false): array
    {
        $year = FiscalYear::where('company_id', $principal->companyId)->find($principal->financialYearId);
        if ($year === null) {
            throw ValidationException::withMessages(['period' => 'Select an authorized financial year.']);
        }

        $start = $year->start_date->toDateString();
        $end = $year->end_date->toDateString();
        foreach ([$from, $to] as $date) {
            if ($date !== null && (!validator(['date' => $date], ['date' => 'date_format:Y-m-d'])->passes() || $date < $start || $date > $end)) {
                throw ValidationException::withMessages(['period' => "Choose a valid date between {$start} and {$end}."]);
            }
        }

        $timezone = Company::whereKey($principal->companyId)->value('timezone') ?: config('app.timezone');
        $today = max($start, min($end, now($timezone)->toDateString()));
        $to ??= max($from ?? $start, $today);
        $from ??= $monthToDate ? max($start, substr($to, 0, 7) . '-01') : $start;
        if ($from > $to) {
            throw ValidationException::withMessages(['period' => "Start date must be on or before end date ({$start} to {$end})."]);
        }

        return ['from' => $from, 'to' => $to, 'timezone' => $timezone];
    }
}
