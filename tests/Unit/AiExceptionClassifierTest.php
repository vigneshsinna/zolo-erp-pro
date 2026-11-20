<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ai\Services\AiExceptionClassifier;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

final class AiExceptionClassifierTest extends TestCase
{
    public function test_reconciliation_is_critical_even_when_warnings_are_present(): void
    {
        $now = Carbon::parse('2026-11-20 10:00:00', 'UTC');
        $classified = (new AiExceptionClassifier())->classify((object) [
            'status' => 'requires_reconciliation',
            'expires_at' => '2026-11-19 10:00:00',
            'preview_json' => '{"warnings":["tax mismatch"]}',
        ], $now);
        $this->assertSame('critical', $classified['priority']);
        $this->assertCount(2, $classified['issues']);
    }

    public function test_expired_and_normal_pending_are_classified_without_models(): void
    {
        $service = new AiExceptionClassifier();
        $now = Carbon::parse('2026-11-20 10:00:00', 'UTC');
        $expired = $service->classify((object) [
            'status' => 'pending', 'expires_at' => '2026-11-19 10:00:00', 'preview_json' => '{}',
        ], $now);
        $normal = $service->classify((object) [
            'status' => 'pending', 'expires_at' => '2026-11-21 10:00:00', 'preview_json' => 'invalid json',
        ], $now);
        $this->assertSame('high', $expired['priority']);
        $this->assertSame('normal', $normal['priority']);
    }
}
