<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait UsesAiMysql
{
    use DatabaseTransactions {
        beginDatabaseTransaction as private beginFixtureTransaction;
    }

    public function beginDatabaseTransaction(): void
    {
        if (getenv('ERP_TEST_MYSQL') !== '1') {
            $this->markTestSkipped('Set ERP_TEST_MYSQL=1 to run the AI MySQL suite.');
        }

        $database = getenv('ERP_TEST_MYSQL_DATABASE') ?: '';
        if (!preg_match('/^zolo_test_[a-z0-9_]+$/D', $database)
            || DB::connection()->getDriverName() !== 'mysql'
            || DB::connection()->getDatabaseName() !== $database) {
            throw new RuntimeException('AI tests require the named disposable MySQL database.');
        }

        $this->beginFixtureTransaction();
        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);
    }
}
