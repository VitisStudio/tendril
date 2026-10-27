<?php

use Filament\Facades\Filament;
use Livewire\Livewire;
use VitisStudio\Tendril\Core\Exceptions\InvalidPredicate;
use VitisStudio\Tendril\Core\Query\Predicate;
use VitisStudio\Tendril\Filament\Pages\CohortsRetention;
use VitisStudio\Tendril\Filament\Pages\Conversions;
use VitisStudio\Tendril\Filament\Pages\Features;
use VitisStudio\Tendril\Filament\Pages\Funnels;
use VitisStudio\Tendril\Filament\Support\PredicateForm;
use VitisStudio\Tendril\Product\Models\Feature;
use Workbench\App\Models\User;

it('serializes builder rows into valid DSL predicates and back', function () {
    $rows = [
        ['field' => 'properties.plan', 'op' => 'in', 'type' => 'list', 'value' => 'pro, team'],
        ['field' => 'properties.amount', 'op' => 'gte', 'type' => 'number', 'value' => '99.5'],
        ['field' => 'properties.paid', 'op' => 'eq', 'type' => 'boolean', 'value' => 'true'],
        ['field' => 'properties.seats', 'op' => 'in', 'type' => 'list', 'value' => '5, 10'],
        ['field' => 'context.route', 'op' => 'exists', 'type' => 'string', 'value' => null],
        ['field' => '', 'op' => 'eq', 'type' => 'string', 'value' => 'ignored'],
    ];

    $tree = PredicateForm::toPredicate($rows);

    expect($tree)->toBe(['all' => [
        ['field' => 'properties.plan', 'op' => 'in', 'value' => ['pro', 'team']],
        ['field' => 'properties.amount', 'op' => 'gte', 'value' => 99.5],
        ['field' => 'properties.paid', 'op' => 'eq', 'value' => true],
        ['field' => 'properties.seats', 'op' => 'in', 'value' => [5, 10]],
        ['field' => 'context.route', 'op' => 'exists'],
    ]])
        ->and(Predicate::fromArray($tree)->toArray())->toBe($tree)
        ->and(PredicateForm::toPredicate(PredicateForm::fromPredicate($tree)))->toBe($tree)
        ->and(PredicateForm::toPredicate([]))->toBeNull();
});

it('rejects invalid builder input', function () {
    PredicateForm::toPredicate([['field' => 'properties.amount', 'op' => 'gt', 'type' => 'string', 'value' => 'lots']]);
})->throws(InvalidPredicate::class);

it('builds valid funnel, cohort and conversion definitions from form data', function () {
    $funnel = Funnels::definitionFrom([
        'key' => 'trial', 'name' => 'Trial', 'window_hours' => 2,
        'steps' => [
            ['event' => 'Page.Viewed', 'label' => 'Pricing', 'conditions' => [['field' => 'context.route', 'op' => 'eq', 'type' => 'string', 'value' => 'pricing']]],
            ['event' => 'trial.started', 'conditions' => []],
        ],
    ]);

    $cohort = CohortsRetention::definitionFrom([
        'key' => 'power', 'name' => 'Power users', 'match' => 'all',
        'conditions' => [['type' => 'used_feature', 'feature' => 'reporting', 'min_days' => '3', 'within_days' => '30']],
    ]);

    $conversion = Conversions::definitionFrom([
        'key' => 'upgrade', 'name' => 'Upgrade', 'event' => 'plan.upgraded',
        'conditions' => [['field' => 'properties.plan', 'op' => 'eq', 'type' => 'string', 'value' => 'pro']],
        'value_path' => 'properties.amount', 'currency' => 'eur',
    ]);

    expect($funnel->windowSeconds)->toBe(7200)
        ->and($funnel->steps[0]['event'])->toBe('page.viewed')
        ->and($funnel->steps[0]['where']->toArray())->toBe(['all' => [['field' => 'context.route', 'op' => 'eq', 'value' => 'pricing']]])
        ->and($cohort->conditions[0])->toMatchArray(['type' => 'used_feature', 'feature' => 'reporting', 'min_days' => 3])
        ->and($conversion->currency)->toBe('EUR')
        ->and($conversion->where->toArray()['all'][0]['value'])->toBe('pro');
});

it('creates data definitions through the page actions', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(Features::class)
        ->callAction('create', data: [
            'key' => 'search',
            'name' => 'Search',
            'events' => ['search.performed'],
            'routes' => ['search.*'],
            'components' => [],
        ])
        ->assertHasNoActionErrors();

    expect(Feature::query()->where('key', 'search')->sole()->definition)->toEqual([
        'events' => ['search.performed'],
        'routes' => ['search.*'],
        'components' => [],
    ]);
});
