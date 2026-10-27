<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Default test case: migrated once per process, each test in a transaction.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;
}
