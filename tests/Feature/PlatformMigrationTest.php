<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\CompanyContextTestCase;

class PlatformMigrationTest extends CompanyContextTestCase
{
    public function test_repeated_migrations_preserve_company_decisions_without_duplicating_constraints(): void
    {
        $capability = DB::table('capabilities')->where('key', 'manufacturing.production')->value('id');
        DB::table('company_capabilities')->insert([
            'company_id' => $this->company->id, 'capability_id' => $capability, 'enabled' => false,
        ]);
        $foreignCounts = [count(Schema::getForeignKeys('company_capabilities')), count(Schema::getForeignKeys('document_series'))];
        $profiles = DB::table('business_profiles')->orderBy('id')->pluck('id')->all();
        (require database_path('migrations/2026_10_03_000004_create_capability_tables.php'))->up();
        (require database_path('migrations/2026_10_03_000005_create_document_numbering_tables.php'))->up();
        $this->assertSame($foreignCounts, [count(Schema::getForeignKeys('company_capabilities')), count(Schema::getForeignKeys('document_series'))]);
        $this->assertFalse((bool) DB::table('company_capabilities')->value('enabled'));
        $this->assertSame($profiles, DB::table('business_profiles')->orderBy('id')->pluck('id')->all());
    }

    public function test_committed_mysql_table_creation_recovers_missing_indexes_and_foreign_keys(): void
    {
        if (getenv('ERP_TEST_MYSQL') !== '1') {
            $this->markTestSkipped('Committed-DDL recovery proof requires disposable MySQL.');
        }
        $numbering = require database_path('migrations/2026_10_03_000005_create_document_numbering_tables.php');
        $capabilities = require database_path('migrations/2026_10_03_000004_create_capability_tables.php');
        $numbering->down();
        $capabilities->down();
        $interruptAt = 'capabilities';
        DB::listen(function (QueryExecuted $query) use (&$interruptAt) {
            if ($interruptAt !== null && str_starts_with($query->sql, 'create table `'.$interruptAt.'`')) {
                $interruptAt = null;
                throw new RuntimeException('Injected failure after committed CREATE TABLE');
            }
        });
        try {
            $capabilities->up();
            $this->fail('DDL interruption must abort migration.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected failure after committed CREATE TABLE', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('capabilities'));
        $this->assertFalse(Schema::hasIndex('capabilities', 'capabilities_key_unique'));
        $capabilities->up();
        $this->assertTrue(Schema::hasIndex('capabilities', 'capabilities_key_unique'));
        $this->assertSame(3, count(Schema::getForeignKeys('company_capabilities')));
        $interruptAt = 'document_series';
        try {
            $numbering->up();
            $this->fail('DDL interruption must abort migration.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected failure after committed CREATE TABLE', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('document_series'));
        $this->assertFalse(Schema::hasIndex('document_series', 'document_series_scope_default'));
        $this->assertCount(0, Schema::getForeignKeys('document_series'));
        $numbering->up();
        $this->assertTrue(Schema::hasIndex('document_series', 'document_series_scope_default'));
        $this->assertCount(3, Schema::getForeignKeys('document_series'));
        $this->assertCount(2, Schema::getForeignKeys('document_number_reservations'));
        $this->assertSame(2, DB::table('companies')->count());
    }
}
