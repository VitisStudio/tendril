<?php

use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Product\Features\FeatureDefinition;
use VitisStudio\Tendril\Product\Features\FeatureRepository;

it('defines features under the Tendril facade (no Pennant collision)', function () {
    Tendril::feature('billing')
        ->name('Billing')
        ->events(['invoice.*'])
        ->routes(['billing.*'])
        ->components(['billing.panel@pay']);

    $feature = app(FeatureRepository::class)->find('billing');

    expect($feature)->not->toBeNull()
        ->and($feature->source)->toBe('code')
        ->and($feature->events)->toBe(['invoice.*']);
});

it('matches events, page-view routes and component actions', function () {
    $feature = FeatureDefinition::fromArray(['key' => 'reporting', 'definition' => [
        'events' => ['report.exported', 'report.shared.*'],
        'routes' => ['reports.*'],
        'components' => ['reports.show@export', 'dashboards.*'],
    ]]);

    expect($feature->matches('report.exported', null, null, null))->toBeTrue()
        ->and($feature->matches('report.shared.email', null, null, null))->toBeTrue()
        ->and($feature->matches('page.viewed', 'reports.show', null, null))->toBeTrue()
        ->and($feature->matches('auth.login', 'reports.show', null, null))->toBeFalse()
        ->and($feature->matches('livewire.action', null, 'reports.show', 'export'))->toBeTrue()
        ->and($feature->matches('livewire.action', null, 'reports.show', 'filter'))->toBeFalse()
        ->and($feature->matches('livewire.action', null, 'dashboards.sales', 'anything'))->toBeTrue()
        ->and($feature->matches('page.viewed', 'billing.index', null, null))->toBeFalse();
});

it('stores data definitions and round-trips JSON import/export', function () {
    $repository = app(FeatureRepository::class);
    $repository->save(FeatureDefinition::fromArray(['key' => 'onboarding', 'name' => 'Onboarding', 'definition' => ['events' => ['onboarding.*']]]));

    $json = $repository->export();
    $repository->delete('onboarding');

    expect($repository->find('onboarding'))->toBeNull()
        ->and($repository->import($json))->toBe(1)
        ->and($repository->find('onboarding')?->events)->toBe(['onboarding.*'])
        ->and($repository->import($json))->toBe(0);
});

it('refuses to edit code-defined features as data', function () {
    app(FeatureRepository::class)->save(FeatureDefinition::fromArray(['key' => 'reporting', 'definition' => ['events' => ['x']]]));
})->throws(InvalidArgumentException::class);

it('validates keys', function () {
    FeatureDefinition::fromArray(['key' => 'Bad Key!']);
})->throws(InvalidArgumentException::class);
