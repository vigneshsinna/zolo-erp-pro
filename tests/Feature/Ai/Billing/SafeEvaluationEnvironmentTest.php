<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Billing;

use App\Support\AiEvaluation\AssertSafeEvaluationEnvironment;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class SafeEvaluationEnvironmentTest extends TestCase
{
    use UsesAiMysql;

    public function test_sec_e01_production_environment_is_strictly_blocked(): void
    {
        $this->app['env'] = 'production';
        config()->set('ai_evaluation.enabled', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("AI evaluation execution forbidden in [production] environment");

        AssertSafeEvaluationEnvironment::assertSafe();
    }

    public function test_sec_e02_disabled_evaluation_flag_blocks_execution(): void
    {
        config()->set('ai_evaluation.enabled', false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("AI evaluation is disabled");

        AssertSafeEvaluationEnvironment::assertSafe();
    }

    public function test_sec_e03_valid_testing_environment_and_database_passes(): void
    {
        $this->app['env'] = 'testing';
        config()->set('ai_evaluation.enabled', true);

        // Active test database begins with zolo_test_
        $this->assertStringStartsWith('zolo_test_', DB::connection()->getDatabaseName());

        // Must not throw
        AssertSafeEvaluationEnvironment::assertSafe();
        $this->assertTrue(true);
    }

    public function test_sec_e04_ephemeral_credentials_are_random_and_unpredictable(): void
    {
        $creds1 = AssertSafeEvaluationEnvironment::generateEphemeralCredentials();
        $creds2 = AssertSafeEvaluationEnvironment::generateEphemeralCredentials();

        $this->assertNotEquals($creds1['email'], $creds2['email']);
        $this->assertNotEquals($creds1['password'], $creds2['password']);
        $this->assertStringEndsWith('@test.internal', $creds1['email']);
        $this->assertEquals(32, strlen($creds1['password']));
    }
}
