<?php

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Exceptions\InvalidPredicate;
use VitisStudio\Tendril\Core\Query\Predicate;
use VitisStudio\Tendril\Core\Support\Tables;

dataset('invalid predicates', [
    'unknown op' => [['field' => 'properties.a', 'op' => 'like', 'value' => 'x']],
    'bad field' => [['field' => 'actor_id', 'op' => 'eq', 'value' => '1']],
    'injection in path' => [['field' => "properties.a'); drop table x; --", 'op' => 'eq', 'value' => 'x']],
    'numeric op on string' => [['field' => 'properties.a', 'op' => 'gt', 'value' => 'x']],
    'empty in' => [['field' => 'properties.a', 'op' => 'in', 'value' => []]],
    'mixed in' => [['field' => 'properties.a', 'op' => 'in', 'value' => ['a', 1]]],
    'missing value' => [['field' => 'properties.a', 'op' => 'eq']],
    'group with extra keys' => [['all' => [], 'any' => []]],
    'version string' => [['field' => 'version', 'op' => 'eq', 'value' => 'one']],
    'too deep' => [['not' => ['not' => ['not' => ['not' => ['not' => ['field' => 'name', 'op' => 'eq', 'value' => 'x']]]]]]],
]);

it('rejects invalid predicates', function (array $tree) {
    Predicate::fromArray($tree);
})->with('invalid predicates')->throws(InvalidPredicate::class);

it('round-trips through JSON', function () {
    $predicate = Predicate::fromArray(['any' => [
        ['field' => 'properties.plan', 'op' => 'in', 'value' => ['pro', 'team']],
        ['not' => ['field' => 'context.route', 'op' => 'starts_with', 'value' => 'admin.']],
    ]]);

    expect(Predicate::fromJson(json_encode($predicate))->toArray())->toBe($predicate->toArray());
});

/**
 * The same predicates evaluated in PHP and compiled to SQL must select the
 * same events on every supported database.
 */
it('has identical semantics in SQL and PHP', function () {
    $at = CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC');

    $fixtures = [
        'a' => ['name' => 'order.completed', 'properties' => ['plan' => 'pro', 'amount' => 120, 'paid' => true, 'meta' => ['tier' => 'gold']], 'context' => ['route' => 'checkout.done']],
        'b' => ['name' => 'order.completed', 'properties' => ['plan' => 'team', 'amount' => 80.5, 'paid' => false], 'context' => ['route' => 'admin.orders']],
        'c' => ['name' => 'order.completed', 'properties' => ['plan' => 'Pro Plus', 'amount' => '300'], 'context' => []],
        'd' => ['name' => 'lead.created', 'properties' => ['service' => 'consulting', 'amount' => 5], 'context' => ['route' => 'contact']],
        'e' => ['name' => 'lead.created', 'properties' => [], 'context' => []],
        'f' => ['name' => 'lead.created', 'properties' => ['service' => '50%_off'], 'context' => []],
    ];

    $ids = [];

    foreach ($fixtures as $key => $fixture) {
        $ids[writeEvent($fixture['name'], $at, ['properties' => $fixture['properties'], 'context' => $fixture['context']])] = $key;
    }

    $cases = [
        ['field' => 'properties.plan', 'op' => 'eq', 'value' => 'pro'],
        ['field' => 'properties.plan', 'op' => 'neq', 'value' => 'pro'],
        ['field' => 'properties.amount', 'op' => 'gt', 'value' => 100],
        ['field' => 'properties.amount', 'op' => 'lte', 'value' => 80.5],
        ['field' => 'properties.amount', 'op' => 'eq', 'value' => 120],
        ['field' => 'properties.paid', 'op' => 'eq', 'value' => true],
        ['field' => 'properties.paid', 'op' => 'neq', 'value' => true],
        ['field' => 'properties.plan', 'op' => 'in', 'value' => ['pro', 'team']],
        ['field' => 'properties.plan', 'op' => 'not_in', 'value' => ['pro']],
        ['field' => 'properties.amount', 'op' => 'in', 'value' => [5, 120]],
        ['field' => 'properties.plan', 'op' => 'contains', 'value' => 'PRO'],
        ['field' => 'properties.service', 'op' => 'contains', 'value' => '%_'],
        ['field' => 'context.route', 'op' => 'starts_with', 'value' => 'admin.'],
        ['field' => 'properties.meta.tier', 'op' => 'eq', 'value' => 'gold'],
        ['field' => 'properties.service', 'op' => 'exists'],
        ['field' => 'properties.service', 'op' => 'not_exists'],
        ['field' => 'name', 'op' => 'eq', 'value' => 'lead.created'],
        ['field' => 'name', 'op' => 'in', 'value' => ['order.completed']],
        ['field' => 'version', 'op' => 'gte', 'value' => 1],
        ['not' => ['field' => 'properties.plan', 'op' => 'eq', 'value' => 'pro']],
        ['all' => [
            ['field' => 'name', 'op' => 'eq', 'value' => 'order.completed'],
            ['any' => [
                ['field' => 'properties.amount', 'op' => 'gte', 'value' => 100],
                ['field' => 'properties.paid', 'op' => 'eq', 'value' => false],
            ]],
        ]],
        ['all' => []],
        ['any' => []],
    ];

    foreach ($cases as $tree) {
        $predicate = Predicate::fromArray($tree);

        $sql = $predicate->apply(db()->table(Tables::EVENTS))->pluck('id')->map(fn ($id) => $ids[(string) $id])->sort()->values()->all();

        $php = collect($fixtures)
            ->filter(fn ($f) => $predicate->matches(['name' => $f['name'], 'version' => 1, 'properties' => $f['properties'], 'context' => $f['context']]))
            ->keys()->sort()->values()->all();

        expect($sql)->toBe($php, 'Mismatch for '.json_encode($tree));
    }
});
