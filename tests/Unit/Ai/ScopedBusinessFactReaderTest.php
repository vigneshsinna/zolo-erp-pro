<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Knowledge\ScopedBusinessFactReader;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Tests\TestCase;

final class ScopedBusinessFactReaderTest extends TestCase
{
    public function test_supported_types_are_closed_allowlist(): void
    {
        $this->assertContains('supplier', ScopedBusinessFactReader::supportedKinds());
        $this->assertContains('job_work_receipt', ScopedBusinessFactReader::supportedKinds());
        $this->assertNotContains('users', ScopedBusinessFactReader::supportedKinds());
    }

    public function test_raw_table_and_sql_like_fact_types_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScopedBusinessFactReader::assertRequest('supplier union select * from users', 1);
    }

    public function test_zero_id_is_rejected_without_database_access(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScopedBusinessFactReader::assertRequest('supplier', 0);
    }

    public function test_off_by_default_before_any_live_fact_lookup(): void
    {
        config(['ai_business_knowledge.enabled' => false]);
        $principal = new AiPrincipal(actorId: 1, companyId: 1, branchId: 1, financialYearId: 1);
        $this->expectException(AuthorizationException::class);
        app(ScopedBusinessFactReader::class)->find($principal, 'supplier', 123);
    }
}
