<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Product\Aggregation\AggregationResult;
use VitisStudio\Tendril\Product\Aggregation\Aggregator;

require_once __DIR__.'/Support/OctaneEvents.php';

uses(TestCase::class)->in('Core', 'Product', 'Marketing', 'Filament', 'Timescale', 'Destinations');
uses(Tests\SchemaTestCase::class)->in('Schema');
uses(Tests\ScaleTestCase::class)->in('Scale');

function db(): Database
{
    return app(Database::class);
}

function driver(): string
{
    return db()->driver();
}

/**
 * Skip unless the test database is one of the given drivers.
 */
function onlyOn(string ...$drivers): void
{
    if (! in_array(driver(), $drivers, true)) {
        test()->markTestSkipped('Requires '.implode(' or ', $drivers).'; running on '.driver().'.');
    }
}

/**
 * Stored events, decoded, ordered by occurrence.
 *
 * @return Collection<int, object>
 */
function storedEvents(?string $name = null): Collection
{
    $query = db()->table(Tables::EVENTS)->orderBy('occurred_at')->orderBy('id');

    if ($name !== null) {
        $query->where('name', $name);
    }

    return $query->get()->map(function ($row) {
        $row->properties = is_string($row->properties) ? json_decode($row->properties, true) : ($row->properties ?? []);
        $row->context = is_string($row->context) ? json_decode($row->context, true) : ($row->context ?? []);

        return $row;
    });
}

/**
 * Carry the (rotating) Laravel session cookie into the next request, like a browser.
 */
function carry(Illuminate\Testing\TestResponse $response): void
{
    $cookie = $response->getCookie(config('session.cookie'), decrypt: true);

    if ($cookie !== null) {
        test()->withCookie(config('session.cookie'), $cookie->getValue());
    }
}

/**
 * @return Collection<int, object>
 */
function sessions(): Collection
{
    return db()->table(Tables::SESSIONS)->orderBy('started_at')->get();
}

function aggregate(): AggregationResult
{
    return app(Aggregator::class)->drain();
}

/**
 * Write an event row straight through the BatchWriter (bypasses capture).
 *
 * @param  array<string, mixed>  $overrides
 */
function writeEvent(string $name, CarbonImmutable $at, array $overrides = []): string
{
    $id = (string) Illuminate\Support\Str::ulid($at);

    app(BatchWriter::class)->write(Batch::make([array_merge([
        'id' => $id,
        'occurred_at' => Instant::serialize($at),
        'received_at' => Instant::serialize($at),
        'name' => $name,
        'version' => 1,
        'actor_type' => null,
        'actor_id' => null,
        'anonymous_id' => null,
        'session_id' => null,
        'tenant_type' => null,
        'tenant_id' => null,
        'request_id' => $id,
        'trace_id' => null,
        'parent_id' => null,
        'dedupe_key' => $id,
        'source' => 'app',
        'environment' => 'testing',
        'properties' => [],
        'context' => [],
    ], $overrides)]));

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function actor(string $id, ?string $tenant = null, array $overrides = []): array
{
    return array_merge([
        'actor_type' => 'App\\Models\\User',
        'actor_id' => $id,
        'tenant_type' => $tenant !== null ? 'App\\Models\\Team' : null,
        'tenant_id' => $tenant,
    ], $overrides);
}
