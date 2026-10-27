<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * For tests that change the schema itself (native partitions, migration
 * safety). DDL cannot live inside a test transaction on MySQL (implicit
 * commit), so these tests migrate fresh themselves and force the next
 * transactional test to re-migrate the standard schema.
 */
abstract class SchemaTestCase extends BaseTestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    protected function freshSchema(array $config = []): void
    {
        config($config);
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }
}
