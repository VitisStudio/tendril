<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * For the load, aging and retention tests. They generate years of data and
 * run the aggregation pipeline, which commits per bucket in production; a
 * single wrapping test transaction with thousands of savepoints exhausts
 * PostgreSQL's lock table, so these tests migrate fresh and commit for real.
 */
abstract class ScaleTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }
}
