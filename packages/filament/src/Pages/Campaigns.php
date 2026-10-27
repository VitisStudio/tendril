<?php

namespace VitisStudio\Tendril\Filament\Pages;

use VitisStudio\Tendril\Marketing\Attribution\Models;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRepository;
use VitisStudio\Tendril\Marketing\Money\Money;
use VitisStudio\Tendril\Marketing\Reports\CampaignReport;
use VitisStudio\Tendril\Marketing\Reports\ConversionPaths;

/**
 * Source / medium / campaign, landing pages, attributed conversions and
 * value. Every report shows the attribution model and lookback it used.
 */
class Campaigns extends TendrilPage
{
    public ?string $attributionModel = null;

    public ?int $lookback = null;

    public ?string $conversion = null;

    public static function tendrilKey(): string
    {
        return 'campaigns';
    }

    public static function tendrilLabel(): string
    {
        return 'Campaigns';
    }

    public static function tendrilIcon(): string
    {
        return 'heroicon-o-megaphone';
    }

    public static function tendrilSort(): int
    {
        return 11;
    }

    /**
     * @return array<string, string>
     */
    public function modelOptions(): array
    {
        return Models::options();
    }

    /**
     * @return array<string, string>
     */
    public function conversionOptions(): array
    {
        $out = [];

        foreach (app(ConversionRepository::class)->all() as $key => $definition) {
            $out[$key] = $definition->name;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $this->attributionModel ??= (string) config('tendril-marketing.attribution.default_model', 'last_touch');
        $this->lookback ??= (int) config('tendril-marketing.attribution.lookback_days', 90);
        $model = in_array($this->attributionModel, Models::NAMES, true) ? $this->attributionModel : 'last_touch';
        $lookback = in_array($this->lookback, [7, 30, 60, 90, 180], true) ? $this->lookback : 90;
        $repository = app(ConversionRepository::class);
        $all = $repository->all();
        $conversions = $this->conversion !== null && isset($all[$this->conversion]) ? [$all[$this->conversion]] : array_values($all);
        $scope = $this->tenantScope();
        $from = $this->rangeStart();
        $to = $this->rangeEnd()->addDay();

        $key = 'tendril:campaigns:'.sha1(json_encode([$model, $lookback, $this->conversion, $scope->cacheKey(), $from->toDateString(), array_map(fn ($c) => $c->hash(), $conversions)]) ?: '');

        return cache()->remember($key, (int) config('tendril-product.report_cache_seconds', 300), function () use ($from, $to, $scope, $conversions, $model, $lookback) {
            $campaigns = app(CampaignReport::class)->campaigns($from, $to, $scope, $conversions, $model, $lookback);
            $paths = count($conversions) === 1
                ? app(ConversionPaths::class)->top($conversions[0], $from, $to, $scope, $lookback)
                : null;

            return [
                'stamp' => $campaigns['attribution']->stamp(),
                'total' => $campaigns['attribution']->totalConversions,
                'values' => $campaigns['attribution']->values,
                'rows' => $campaigns['rows'],
                'landing' => app(CampaignReport::class)->landingPages($from, $to, $scope),
                'paths' => $paths,
            ];
        });
    }

    public static function money(int $minor, string $currency): string
    {
        return Money::format($minor, $currency);
    }
}
