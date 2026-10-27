<?php

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Product\Cohorts\CohortDefinition;
use VitisStudio\Tendril\Product\Cohorts\CohortEvaluator;
use VitisStudio\Tendril\Product\Retention\RetentionCalculator;

beforeEach(function () {
    $this->monday = CarbonImmutable::parse('2026-08-03 12:00:00', 'UTC');

    // Week 0 cohort: 1, 2, 3. Week 1 cohort: 4.
    foreach (['1', '2', '3'] as $id) {
        writeEvent('auth.login', $this->monday, actor($id));
    }

    writeEvent('report.exported', $this->monday->addDay(), actor('1') + ['properties' => ['format' => 'xlsx']]);
    writeEvent('report.exported', $this->monday->addDays(2), actor('1') + ['properties' => ['format' => 'csv']]);
    writeEvent('report.exported', $this->monday->addDay(), actor('2') + ['properties' => ['format' => 'csv']]);

    // Returns: 1 in week 1 and 2; 2 in week 2.
    writeEvent('auth.login', $this->monday->addWeek(), actor('1'));
    writeEvent('auth.login', $this->monday->addWeeks(2), actor('1'));
    writeEvent('auth.login', $this->monday->addWeeks(2), actor('2'));
    writeEvent('auth.login', $this->monday->addWeek(), actor('4'));

    aggregate();
});

it('evaluates behavioural cohorts as of any date', function () {
    $evaluator = app(CohortEvaluator::class);
    $asOf = $this->monday->addDays(3);

    $exporters = CohortDefinition::fromArray(['key' => 'exporters', 'definition' => ['conditions' => [
        ['type' => 'performed', 'event' => 'report.exported', 'min_count' => 2, 'within_days' => 7],
    ]]]);

    $csv = CohortDefinition::fromArray(['key' => 'csv', 'definition' => ['conditions' => [
        ['type' => 'performed', 'event' => 'report.exported', 'where' => ['all' => [['field' => 'properties.format', 'op' => 'eq', 'value' => 'csv']]], 'within_days' => 7],
    ]]]);

    $dormant = CohortDefinition::fromArray(['key' => 'dormant', 'definition' => ['conditions' => [
        ['type' => 'not_performed', 'event' => 'report.exported', 'within_days' => 7],
    ]]]);

    $newOrExporters = CohortDefinition::fromArray(['key' => 'mix', 'definition' => ['match' => 'any', 'conditions' => [
        ['type' => 'first_seen', 'within_days' => 3],
        ['type' => 'used_feature', 'feature' => 'reporting', 'within_days' => 7],
    ]]]);

    expect(array_keys($evaluator->members($exporters, $asOf, TenantScope::all())))->toBe(['App\\Models\\User|1'])
        ->and(array_keys($evaluator->members($csv, $asOf, TenantScope::all())))->toBe(['App\\Models\\User|1', 'App\\Models\\User|2'])
        ->and(array_keys($evaluator->members($dormant, $asOf, TenantScope::all())))->toBe(['App\\Models\\User|3'])
        ->and($evaluator->size($newOrExporters, $asOf, TenantScope::all()))->toBe(2)
        // Historical: as of the Monday itself nobody had exported yet.
        ->and($evaluator->size($exporters, $this->monday, TenantScope::all()))->toBe(0);
});

it('builds weekly retention matrices from the activity table', function () {
    $matrix = app(RetentionCalculator::class)->matrix($this->monday, $this->monday->addWeeks(2), TenantScope::all(), 'week', 3);

    expect($matrix)->toBe([
        ['cohort' => '2026-08-03', 'size' => 3, 'counts' => [3, 1, 2], 'rates' => [1.0, 0.3333, 0.6667]],
        ['cohort' => '2026-08-10', 'size' => 1, 'counts' => [1, 0, 0], 'rates' => [1.0, 0.0, 0.0]],
    ]);
});

it('restricts retention to cohort members and supports feature returns', function () {
    $members = app(CohortEvaluator::class)->members(
        CohortDefinition::fromArray(['key' => 'x', 'definition' => ['conditions' => [['type' => 'performed', 'event' => 'report.exported', 'within_days' => 30]]]]),
        $this->monday->addDays(3),
        TenantScope::all(),
    );

    $matrix = app(RetentionCalculator::class)->matrix($this->monday, $this->monday->addWeeks(2), TenantScope::all(), 'week', 2, $members, 'reporting', 'feature');

    expect($matrix)->toBe([
        ['cohort' => '2026-08-03', 'size' => 2, 'counts' => [2, 0], 'rates' => [1.0, 0.0]],
    ]);
});
