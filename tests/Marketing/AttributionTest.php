<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Marketing\Attribution\AttributionEngine;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRepository;
use VitisStudio\Tendril\Marketing\Reports\CampaignReport;
use VitisStudio\Tendril\Marketing\Reports\ConversionPaths;

function touchpoint(string $anonymous, CarbonImmutable $at, string $source, string $medium, string $campaign, array $extra = []): void
{
    app(BatchWriter::class)->write(Batch::make(touchpoints: [array_merge([
        'session_id' => (string) Str::ulid($at),
        'occurred_at' => Instant::serialize($at),
        'anonymous_id' => $anonymous,
        'source' => $source,
        'medium' => $medium,
        'campaign' => $campaign,
        'landing_route' => 'home',
        'environment' => 'testing',
    ], $extra)]));
}

function aliasLink(string $anonymous, string $actor, CarbonImmutable $at, ?CarbonImmutable $since = null): void
{
    app(BatchWriter::class)->write(Batch::make(aliases: [[
        'action' => 'link', 'anonymous_id' => $anonymous, 'actor_type' => 'App\\Models\\User', 'actor_id' => $actor,
        'at' => Instant::serialize($at), 'since' => $since !== null ? Instant::serialize($since) : null, 'reason' => 'registered',
    ]]));
}

beforeEach(function () {
    $d = fn (int $n) => CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC')->addDays($n);
    $this->from = $d(0)->startOfDay();
    $this->to = $d(10);

    // User 10: old touch outside the 90-day lookback, then google, then newsletter; registers, then buys.
    touchpoint('v10', $d(-100), 'bing', 'cpc', 'old');
    touchpoint('v10', $d(1), 'google', 'cpc', 'brand');
    touchpoint('v10', $d(5), 'newsletter', 'email', 'sept');
    aliasLink('v10', '10', $d(5), $d(-100));
    writeEvent('order.completed', $d(6), actor('10') + ['anonymous_id' => 'v10', 'properties' => ['revenue' => '100.00', 'currency' => 'USD']]);

    // User 20: no touchpoints at all -> (direct).
    writeEvent('order.completed', $d(3), actor('20') + ['properties' => ['revenue' => '50.00', 'currency' => 'USD']]);

    // Shared kiosk: user 30 then user 40. Only 40's range may credit 40's purchase.
    touchpoint('kiosk', $d(2), 'facebook', 'social', 'fb');
    touchpoint('kiosk', $d(5)->addHour(), 'tiktok', 'social', 'tt');
    aliasLink('kiosk', '30', $d(2), $d(2));
    aliasLink('kiosk', '40', $d(4));
    writeEvent('order.completed', $d(6), actor('40') + ['anonymous_id' => 'kiosk', 'properties' => ['revenue' => '25.00', 'currency' => 'USD']]);

    $this->purchase = app(ConversionRepository::class)->find('purchase');
});

function rowFor(array $rows, string $source): ?array
{
    foreach ($rows as $row) {
        if ($row['source'] === $source) {
            return $row;
        }
    }

    return null;
}

it('attributes last touch with the model and window stamped', function () {
    $report = app(AttributionEngine::class)->report([$this->purchase], $this->from, $this->to, TenantScope::all(), 'last_touch', 90);

    expect($report->stamp())->toBe('Last touch, 90-day lookback')
        ->and($report->jsonSerialize()['model'])->toBe('last_touch')
        ->and($report->totalConversions)->toBe(3)
        ->and($report->values)->toBe(['USD' => 17500])
        ->and(rowFor($report->rows, 'newsletter'))->toMatchArray(['conversions' => 1.0, 'values' => ['USD' => 10000]])
        ->and(rowFor($report->rows, '(direct)'))->toMatchArray(['conversions' => 1.0, 'values' => ['USD' => 5000]])
        ->and(rowFor($report->rows, 'tiktok'))->toMatchArray(['conversions' => 1.0, 'values' => ['USD' => 2500]])
        ->and(rowFor($report->rows, 'facebook'))->toBeNull()
        ->and(rowFor($report->rows, 'google'))->toBeNull();
});

it('attributes first touch within the lookback window only', function () {
    $report = app(AttributionEngine::class)->report([$this->purchase], $this->from, $this->to, TenantScope::all(), 'first_touch', 90);

    expect(rowFor($report->rows, 'google')['conversions'])->toBe(1.0)
        ->and(rowFor($report->rows, 'bing'))->toBeNull();

    $short = app(AttributionEngine::class)->report([$this->purchase], $this->from, $this->to, TenantScope::all(), 'first_touch', 3);

    expect($short->stamp())->toBe('First touch, 3-day lookback')
        ->and(rowFor($short->rows, 'newsletter')['conversions'])->toBe(1.0)
        ->and(rowFor($short->rows, 'google'))->toBeNull();
});

it('splits credit and value exactly for linear and position-based models', function () {
    $linear = app(AttributionEngine::class)->report([$this->purchase], $this->from, $this->to, TenantScope::all(), 'linear', 90);
    $position = app(AttributionEngine::class)->report([$this->purchase], $this->from, $this->to, TenantScope::all(), 'position_based', 90);

    expect(rowFor($linear->rows, 'google'))->toMatchArray(['conversions' => 0.5, 'values' => ['USD' => 5000]])
        ->and(rowFor($linear->rows, 'newsletter'))->toMatchArray(['conversions' => 0.5, 'values' => ['USD' => 5000]])
        ->and(array_sum(array_map(fn ($r) => $r['values']['USD'] ?? 0, $linear->rows)))->toBe(17500)
        ->and(array_sum(array_column($position->rows, 'conversions')))->toBe(3.0);
});

it('reports campaigns with arrivals, landing pages and attributed value', function () {
    $campaigns = app(CampaignReport::class)->campaigns($this->from, $this->to, TenantScope::all(), [$this->purchase], 'last_touch', 90);

    expect($campaigns['attribution']->stamp())->toBe('Last touch, 90-day lookback')
        ->and(rowFor($campaigns['rows'], 'google'))->toMatchArray(['sessions' => 1, 'conversions' => 0.0])
        ->and(rowFor($campaigns['rows'], 'newsletter'))->toMatchArray(['sessions' => 1, 'conversions' => 1.0])
        ->and(app(CampaignReport::class)->landingPages($this->from, $this->to, TenantScope::all()))->toBe([['landing_route' => 'home', 'sessions' => 4]]);
});

it('shows anonymous-to-known conversion paths', function () {
    $paths = app(ConversionPaths::class)->top($this->purchase, $this->from, $this->to, TenantScope::all(), 90);

    $byPath = collect($paths['paths'])->mapWithKeys(fn ($p) => [implode(' > ', $p['path']) => $p['conversions']])->all();

    expect($paths['total'])->toBe(3)
        ->and($paths['anonymous_to_known'])->toBe(2)
        ->and($byPath)->toMatchArray([
            'google / cpc > newsletter / email' => 1,
            '(direct) / (none)' => 1,
            'tiktok / social' => 1,
        ]);
});
