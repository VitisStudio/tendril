<?php

namespace VitisStudio\Tendril\Marketing;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use VitisStudio\Tendril\Core\Tendril;
use VitisStudio\Tendril\Marketing\Aggregation\ConversionProcessor;
use VitisStudio\Tendril\Marketing\Attribution\AttributionEngine;
use VitisStudio\Tendril\Marketing\Commands\ConversionsCommand;
use VitisStudio\Tendril\Marketing\Conversions\ConversionBuilder;
use VitisStudio\Tendril\Marketing\Conversions\ConversionEvaluator;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRegistry;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRepository;
use VitisStudio\Tendril\Marketing\Reports\CampaignReport;
use VitisStudio\Tendril\Marketing\Reports\ConversionPaths;
use VitisStudio\Tendril\Product\Aggregation\DefinitionVersions;
use VitisStudio\Tendril\Product\Aggregation\ProcessorRegistry;
use VitisStudio\Tendril\Product\Aggregation\Rollups;

final class MarketingServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('tendril-marketing')
            ->hasConfigFile('tendril-marketing')
            ->hasCommand(ConversionsCommand::class);
    }

    public function packageRegistered(): void
    {
        foreach ([
            ConversionRegistry::class, ConversionRepository::class, ConversionEvaluator::class,
            AttributionEngine::class, CampaignReport::class, ConversionPaths::class,
        ] as $class) {
            $this->app->singleton($class);
        }
    }

    public function packageBooted(): void
    {
        if (config('tendril.database.run_migrations', true)) {
            $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([dirname(__DIR__).'/database/migrations' => database_path('migrations')], 'tendril-marketing-migrations');
        }

        Tendril::macro('conversion', fn (string $key): ConversionBuilder => new ConversionBuilder(app(ConversionRegistry::class), $key));

        $this->app->make(ProcessorRegistry::class)->register('conversions', fn () => new ConversionProcessor(
            app(Rollups::class),
            app(ConversionRepository::class),
            app(ConversionEvaluator::class),
        ));

        $this->app->make(DefinitionVersions::class)->register('conversions', fn () => app(ConversionRepository::class)->hash());
    }
}
