<?php

namespace VitisStudio\Tendril\Product;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use VitisStudio\Tendril\Core\Diagnostics\Diagnostics;
use VitisStudio\Tendril\Core\Tendril;
use VitisStudio\Tendril\Product\Aggregation\Aggregator;
use VitisStudio\Tendril\Product\Aggregation\DefinitionVersions;
use VitisStudio\Tendril\Product\Aggregation\ProcessorRegistry;
use VitisStudio\Tendril\Product\Aggregation\Processors\ActivityProcessor;
use VitisStudio\Tendril\Product\Aggregation\Processors\EventRollupProcessor;
use VitisStudio\Tendril\Product\Aggregation\Processors\SessionProcessor;
use VitisStudio\Tendril\Product\Aggregation\Rollups;
use VitisStudio\Tendril\Product\Cardinality\CardinalityGuard;
use VitisStudio\Tendril\Product\Cohorts\CohortEvaluator;
use VitisStudio\Tendril\Product\Commands\AggregateCommand;
use VitisStudio\Tendril\Product\Commands\BackfillCommand;
use VitisStudio\Tendril\Product\Commands\DefinitionsCommand;
use VitisStudio\Tendril\Product\Commands\RebuildRollupsCommand;
use VitisStudio\Tendril\Product\Diagnostics\ProductChecks;
use VitisStudio\Tendril\Product\Features\FeatureBuilder;
use VitisStudio\Tendril\Product\Features\FeatureRegistry;
use VitisStudio\Tendril\Product\Features\FeatureRepository;
use VitisStudio\Tendril\Product\Funnels\FunnelEngine;
use VitisStudio\Tendril\Product\Journeys\JourneyAnalyzer;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;
use VitisStudio\Tendril\Product\Privacy\ActivityEraser;
use VitisStudio\Tendril\Product\Retention\RetentionCalculator;

final class ProductServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('tendril-product')
            ->hasConfigFile('tendril-product')
            ->hasCommands([
                AggregateCommand::class,
                RebuildRollupsCommand::class,
                BackfillCommand::class,
                DefinitionsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        foreach ([
            FeatureRegistry::class, FeatureRepository::class, ProcessorRegistry::class, DefinitionVersions::class,
            Aggregator::class, Rollups::class, ProductMetrics::class, FunnelEngine::class, CohortEvaluator::class,
            RetentionCalculator::class, JourneyAnalyzer::class, CardinalityGuard::class, ActivityEraser::class,
        ] as $class) {
            $this->app->singleton($class);
        }

        $this->app->tag([ActivityEraser::class], 'tendril.erasers');
    }

    public function packageBooted(): void
    {
        if (config('tendril.database.run_migrations', true)) {
            $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([dirname(__DIR__).'/database/migrations' => database_path('migrations')], 'tendril-product-migrations');
        }

        Tendril::macro('feature', fn (string $key): FeatureBuilder => new FeatureBuilder(app(FeatureRegistry::class), $key));

        $registry = $this->app->make(ProcessorRegistry::class);
        $registry->register('events', fn () => new EventRollupProcessor(app(Rollups::class)));
        $registry->register('sessions', fn () => new SessionProcessor(app(Rollups::class)));
        $registry->register('activity', fn () => new ActivityProcessor);

        $this->app->make(DefinitionVersions::class)->register('features', fn () => app(FeatureRepository::class)->hash());

        $diagnostics = $this->app->make(Diagnostics::class);

        foreach (ProductChecks::all() as $check) {
            $diagnostics->register($check);
        }
    }
}
