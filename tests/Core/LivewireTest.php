<?php

use Livewire\Livewire;
use VitisStudio\Tendril\Core\Facades\Tendril;
use Workbench\App\Livewire\ReportBuilder;

it('records component and action names, never parameters', function () {
    Livewire::test(ReportBuilder::class)
        ->call('export', 'SECRET-PARAMETER-VALUE')
        ->call('applyFilter');

    Tendril::flush();

    $actions = storedEvents('livewire.action');

    expect($actions)->toHaveCount(2)
        ->and($actions->pluck('context')->all())->toEqual([
            ['component' => 'report-builder', 'action' => 'export'],
            ['component' => 'report-builder', 'action' => 'applyFilter'],
        ])
        ->and($actions->pluck('source')->unique()->all())->toBe(['livewire'])
        ->and(storedEvents('report.exported'))->toHaveCount(1)
        ->and(json_encode(storedEvents()->all()))->not->toContain('SECRET-PARAMETER-VALUE');
});

it('excludes property syncs, polls, framework calls and #[Untracked] handlers', function () {
    Livewire::test(ReportBuilder::class)
        ->set('filter', 'typing')
        ->call('pollStats')
        ->call('$refresh');

    Tendril::flush();

    expect(storedEvents('livewire.action'))->toHaveCount(0);
});

it('keeps repeated and bundled actions distinguishable', function () {
    Livewire::test(ReportBuilder::class)
        ->call('export')
        ->call('export');

    Tendril::flush();

    $actions = storedEvents('livewire.action');

    expect($actions)->toHaveCount(2)
        ->and($actions->pluck('dedupe_key')->unique())->toHaveCount(2)
        ->and($actions->pluck('request_id')->unique())->toHaveCount(1)
        ->and($actions->pluck('dedupe_key')->all())->each->toContain(':report-builder:export:');
});

it('honours configured exclusions', function () {
    config(['tendril.livewire.exclude_actions' => ['applyFilter'], 'tendril.livewire.exclude_components' => []]);

    Livewire::test(ReportBuilder::class)->call('applyFilter')->call('export');
    Tendril::flush();

    expect(storedEvents('livewire.action')->pluck('context')->pluck('action')->all())->toBe(['export']);
});
