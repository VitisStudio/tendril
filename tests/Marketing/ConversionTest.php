<?php

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Query\Predicate;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Marketing\Conversions\ConversionDefinition;
use VitisStudio\Tendril\Marketing\Conversions\ConversionEvaluator;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRepository;

beforeEach(function () {
    $this->t = CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC');
});

it('defines conversions in code and as data, evaluable over existing history', function () {
    writeEvent('lead.created', $this->t, ['anonymous_id' => 'v1', 'properties' => ['service' => 'consulting']]);
    writeEvent('lead.created', $this->t->addHour(), ['anonymous_id' => 'v2', 'properties' => ['service' => 'design']]);

    // Created after the facts exist.
    Tendril::conversion('consulting-lead')->name('Consulting lead')->event('lead.created')->where([['properties.service', 'eq', 'consulting']]);

    $conversion = app(ConversionRepository::class)->find('consulting-lead');
    $summary = app(ConversionEvaluator::class)->summary($conversion, $this->t->startOfDay(), $this->t->endOfDay(), TenantScope::all());

    expect($conversion->source)->toBe('code')
        ->and($summary)->toBe(['conversions' => 1, 'converters' => 1, 'values' => []]);
});

it('extracts revenue in minor units with a currency', function () {
    writeEvent('order.completed', $this->t, actor('1') + ['properties' => ['revenue' => '49.99', 'currency' => 'eur']]);
    writeEvent('order.completed', $this->t->addMinute(), actor('2') + ['properties' => ['revenue' => 10.5]]);
    writeEvent('order.completed', $this->t->addMinutes(2), actor('3') + ['properties' => ['revenue' => 'n/a']]);

    $purchase = app(ConversionRepository::class)->find('purchase');
    $records = app(ConversionEvaluator::class)->records($purchase, $this->t->startOfDay(), $this->t->endOfDay(), TenantScope::all());

    expect(array_map(fn ($r) => [$r->valueMinor, $r->currency], $records))->toBe([
        [4999, 'EUR'],
        [1050, 'USD'],
        [null, null],
    ]);

    $minor = ConversionDefinition::fromArray(['key' => 'm', 'definition' => ['event' => 'order.completed', 'value_path' => 'properties.cents', 'value_in_minor_units' => true, 'currency' => 'JPY']]);
    expect($minor->valueFrom(['cents' => 1500]))->toBe([1500, 'JPY']);
});

it('rolls up conversions per currency and records unique converters', function () {
    writeEvent('order.completed', $this->t, actor('1', '1') + ['properties' => ['revenue' => '10.00', 'currency' => 'USD']]);
    writeEvent('order.completed', $this->t->addMinute(), actor('1', '1') + ['properties' => ['revenue' => '5.50', 'currency' => 'USD']]);
    writeEvent('order.completed', $this->t->addMinutes(2), actor('2', '1') + ['properties' => ['revenue' => '7.00', 'currency' => 'EUR']]);

    aggregate();

    $day = fn (string $dimension, string $column) => (int) db()->table(Tables::ROLLUPS)
        ->where('granularity', 'day')->where('kind', 'conversion')->where('dimension', $dimension)
        ->where('bucket_start', db()->time(Instant::day($this->t)))->value($column);

    expect($day('purchase', 'count'))->toBe(3)
        ->and($day('purchase:USD', 'sum'))->toBe(1550)
        ->and($day('purchase:EUR', 'sum'))->toBe(700)
        ->and(db()->table(Tables::ACTIVITY)->where('kind', 'conversion')->where('subject', 'purchase')->count())->toBe(2);
});

it('validates definitions and protects code-defined keys', function () {
    expect(fn () => ConversionDefinition::fromArray(['key' => 'x', 'definition' => ['event' => 'Bad Event']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ConversionDefinition::fromArray(['key' => 'x', 'definition' => ['event' => 'a.b', 'value_path' => 'context.revenue']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ConversionRepository::class)->save(ConversionDefinition::fromArray(['key' => 'purchase', 'definition' => ['event' => 'a.b']])))->toThrow(InvalidArgumentException::class);
});

it('round-trips data definitions through JSON', function () {
    $repository = app(ConversionRepository::class);
    $repository->save(new ConversionDefinition('demo-booked', 'Demo booked', 'demo.booked', Predicate::where([['properties.size', 'gte', 10]])));

    $json = $repository->export();
    VitisStudio\Tendril\Marketing\Models\Conversion::query()->where('key', 'demo-booked')->delete();

    expect($repository->import($json))->toBe(1)
        ->and($repository->find('demo-booked')?->where->toArray())->toBe(['all' => [['field' => 'properties.size', 'op' => 'gte', 'value' => 10]]]);
});
