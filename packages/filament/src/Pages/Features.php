<?php

namespace VitisStudio\Tendril\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Filament\Support\TendrilFilament;
use VitisStudio\Tendril\Filament\Widgets\FeatureTrendChart;
use VitisStudio\Tendril\Product\Features\FeatureDefinition;
use VitisStudio\Tendril\Product\Features\FeaturePreview;
use VitisStudio\Tendril\Product\Features\FeatureRepository;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;
use VitisStudio\Tendril\Product\Models\Feature;
use VitisStudio\Tendril\Product\Retention\RetentionCalculator;

/**
 * Feature definitions (data) with adoption, unique actors, frequency,
 * daily trend and retention. Code-defined features are listed read-only.
 */
class Features extends TendrilPage implements HasTable
{
    use InteractsWithTable;

    #[Url]
    public ?string $feature = null;

    public static function tendrilKey(): string
    {
        return 'features';
    }

    public static function tendrilLabel(): string
    {
        return 'Features';
    }

    public static function tendrilIcon(): string
    {
        return 'heroicon-o-puzzle-piece';
    }

    public static function tendrilSort(): int
    {
        return 3;
    }

    protected function getHeaderWidgets(): array
    {
        return [FeatureTrendChart::class];
    }

    public function getWidgetData(): array
    {
        return ['days' => $this->days, 'feature' => $this->feature];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New feature')
                ->icon('heroicon-o-plus')
                ->visible(fn () => TendrilFilament::canManage())
                ->schema(self::formSchema(true))
                ->action(function (array $data): void {
                    $this->saveFeature($data);
                }),
            Action::make('preview')
                ->label('Preview definition')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->schema(self::formSchema(true))
                ->action(function (array $data): void {
                    $this->previewFeature($data);
                }),
            ...parent::getHeaderActions(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Feature::query()->orderBy('key'))
            ->columns([
                TextColumn::make('key')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('definition')
                    ->label('Matches')
                    ->state(fn (Feature $record) => self::summary(FeatureDefinition::fromArray(['key' => $record->key, 'definition' => $record->definition]))),
                TextColumn::make('uniques')
                    ->label('Unique actors')
                    ->state(fn (Feature $record) => $this->metrics($record->key)['uniques']),
                TextColumn::make('adoption')
                    ->label('Adoption')
                    ->state(fn (Feature $record) => number_format($this->metrics($record->key)['adoption'] * 100, 1).'%'),
                TextColumn::make('frequency')
                    ->label('Days / adopter')
                    ->state(fn (Feature $record) => $this->metrics($record->key)['frequency']),
                TextColumn::make('events')
                    ->label('Events')
                    ->state(fn (Feature $record) => number_format($this->metrics($record->key)['events'])),
            ])
            ->recordUrl(fn (Feature $record) => static::getUrl(['feature' => $record->key]))
            ->recordActions([
                Action::make('edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn () => TendrilFilament::canManage())
                    ->fillForm(fn (Feature $record) => [
                        'key' => $record->key,
                        'name' => $record->name,
                        'description' => $record->description,
                        'events' => $record->definition['events'] ?? [],
                        'routes' => $record->definition['routes'] ?? [],
                        'components' => $record->definition['components'] ?? [],
                    ])
                    ->schema(self::formSchema(false))
                    ->action(function (Feature $record, array $data): void {
                        $this->saveFeature(['key' => $record->key] + $data);
                    }),
                Action::make('delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn () => TendrilFilament::canManage())
                    ->action(function (Feature $record): void {
                        abort_unless(TendrilFilament::canManage(), 403);
                        $record->delete();
                    }),
            ])
            ->paginated([10, 25, 50]);
    }

    /**
     * @return array<string, FeatureDefinition>
     */
    public function codeFeatures(): array
    {
        $repository = app(FeatureRepository::class);

        return array_filter($repository->all(true), fn (FeatureDefinition $f) => $repository->isCodeDefined($f->key));
    }

    /**
     * @return array{uniques: int, active: int, adoption: float, frequency: float, events: int}
     */
    public function metrics(string $feature): array
    {
        return app(ProductMetrics::class)->feature($feature, $this->rangeStart(), $this->rangeEnd(), $this->tenantScope());
    }

    /**
     * Weekly retention of actors, returning = used the selected feature again.
     *
     * @return list<array{cohort: string, size: int, counts: list<int>, rates: list<float>}>
     */
    public function retention(): array
    {
        if ($this->feature === null) {
            return [];
        }

        return app(RetentionCalculator::class)->matrix(
            Instant::day(Instant::now())->subWeeks(8),
            Instant::day(Instant::now()),
            $this->tenantScope(),
            'week',
            8,
            null,
            $this->feature,
            'feature',
        );
    }

    public static function summary(FeatureDefinition $feature): string
    {
        $parts = [];

        foreach (['events' => $feature->events, 'routes' => $feature->routes, 'components' => $feature->components] as $label => $patterns) {
            if ($patterns !== []) {
                $parts[] = $label.': '.implode(', ', $patterns);
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function formSchema(bool $withKey): array
    {
        return array_values(array_filter([
            $withKey ? TextInput::make('key')->required()->maxLength(100)->regex(FeatureDefinition::KEY_PATTERN)->helperText('Lower-case, e.g. reporting') : null,
            TextInput::make('name')->required()->maxLength(191),
            Textarea::make('description')->rows(2),
            TagsInput::make('events')->placeholder('report.viewed, report.*')->helperText('Event name patterns (* wildcard).'),
            TagsInput::make('routes')->placeholder('reports.*')->helperText('Page-view route name/URI patterns.'),
            TagsInput::make('components')->placeholder('reports.show@export')->helperText('Livewire component[@action] patterns.'),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveFeature(array $data): void
    {
        abort_unless(TendrilFilament::canManage(), 403);

        try {
            $feature = self::definitionFrom($data);

            if ($feature->isEmpty()) {
                throw new InvalidArgumentException('A feature needs at least one event, route or component pattern.');
            }

            app(FeatureRepository::class)->save($feature);

            Notification::make()->title('Feature saved')->body('History will be re-evaluated on the next aggregation run.')->success()->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Invalid feature')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function previewFeature(array $data): void
    {
        try {
            $result = app(FeaturePreview::class)->preview(self::definitionFrom($data), Instant::now()->subDays(6), Instant::now(), $this->tenantScope());

            Notification::make()
                ->title('Historical preview')
                ->body("Last {$result['days']} days: {$result['uniques']} unique actors, {$result['events']} matching events.")
                ->info()
                ->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Invalid feature')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function definitionFrom(array $data): FeatureDefinition
    {
        return FeatureDefinition::fromArray([
            'key' => (string) ($data['key'] ?? ''),
            'name' => (string) ($data['name'] ?? ''),
            'description' => $data['description'] ?? null,
            'definition' => [
                'events' => array_values((array) ($data['events'] ?? [])),
                'routes' => array_values((array) ($data['routes'] ?? [])),
                'components' => array_values((array) ($data['components'] ?? [])),
            ],
        ]);
    }
}
