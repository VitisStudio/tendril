<?php

namespace VitisStudio\Tendril\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use InvalidArgumentException;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Filament\Support\TendrilFilament;
use VitisStudio\Tendril\Product\Cohorts\CohortDefinition;
use VitisStudio\Tendril\Product\Cohorts\CohortEvaluator;
use VitisStudio\Tendril\Product\Models\Cohort;
use VitisStudio\Tendril\Product\Retention\RetentionCalculator;

/**
 * Saved behavioral cohorts (evaluable as of any date) and retention matrices
 * from the actor-activity table.
 */
class CohortsRetention extends TendrilPage implements HasTable
{
    use InteractsWithTable;

    public string $retentionPeriod = 'week';

    public ?string $retentionCohort = null;

    public static function tendrilKey(): string
    {
        return 'cohorts';
    }

    public static function tendrilLabel(): string
    {
        return 'Cohorts & Retention';
    }

    public static function tendrilIcon(): string
    {
        return 'heroicon-o-user-group';
    }

    public static function tendrilSort(): int
    {
        return 6;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New cohort')
                ->icon('heroicon-o-plus')
                ->visible(fn () => TendrilFilament::canManage())
                ->schema(self::formSchema(true))
                ->action(function (array $data): void {
                    $this->saveCohort($data);
                }),
            ...parent::getHeaderActions(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Cohort::query()->orderBy('key'))
            ->columns([
                TextColumn::make('key')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('conditions')
                    ->label('Conditions')
                    ->state(fn (Cohort $record) => ($record->definition['match'] ?? 'all').': '.implode('; ', array_map(
                        fn ($c) => is_array($c) ? implode(' ', array_filter([(string) ($c['type'] ?? ''), (string) ($c['event'] ?? $c['feature'] ?? $c['value'] ?? ''), isset($c['within_days']) ? "within {$c['within_days']}d" : null])) : '',
                        (array) ($record->definition['conditions'] ?? []),
                    ))),
                TextColumn::make('size')
                    ->label('Size today')
                    ->state(fn (Cohort $record) => $this->size($record)),
            ])
            ->recordActions([
                Action::make('retention')
                    ->label('Retention')
                    ->icon('heroicon-o-table-cells')
                    ->action(function (Cohort $record): void {
                        $this->retentionCohort = $record->key;
                    }),
                Action::make('edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn () => TendrilFilament::canManage())
                    ->fillForm(fn (Cohort $record) => [
                        'key' => $record->key,
                        'name' => $record->name,
                        'match' => $record->definition['match'] ?? 'all',
                        'conditions' => (array) ($record->definition['conditions'] ?? []),
                    ])
                    ->schema(self::formSchema(false))
                    ->action(function (Cohort $record, array $data): void {
                        $this->saveCohort(['key' => $record->key] + $data);
                    }),
                Action::make('delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn () => TendrilFilament::canManage())
                    ->action(function (Cohort $record): void {
                        abort_unless(TendrilFilament::canManage(), 403);
                        $record->delete();
                    }),
            ])
            ->paginated([10, 25]);
    }

    public function size(Cohort $record): int|string
    {
        try {
            return app(CohortEvaluator::class)->size(
                CohortDefinition::fromArray(['key' => $record->key, 'name' => $record->name, 'definition' => $record->definition]),
                Instant::now(),
                $this->tenantScope(),
            );
        } catch (InvalidArgumentException) {
            return 'invalid';
        }
    }

    /**
     * @return list<array{cohort: string, size: int, counts: list<int>, rates: list<float>}>
     */
    public function matrix(): array
    {
        $period = in_array($this->retentionPeriod, ['day', 'week', 'month'], true) ? $this->retentionPeriod : 'week';
        $periods = match ($period) {
            'day' => 14,
            'month' => 6,
            default => 8,
        };
        $from = match ($period) {
            'day' => Instant::day(Instant::now())->subDays(13),
            'month' => Instant::day(Instant::now())->startOfMonth()->subMonthsNoOverflow(5),
            default => Instant::day(Instant::now())->subWeeks(7)->startOfWeek(),
        };

        $scope = $this->tenantScope();
        $members = null;

        if ($this->retentionCohort !== null) {
            $model = Cohort::query()->where('key', $this->retentionCohort)->first();

            if ($model !== null) {
                $members = app(CohortEvaluator::class)->members(
                    CohortDefinition::fromArray(['key' => $model->key, 'name' => $model->name, 'definition' => $model->definition]),
                    Instant::now(),
                    $scope,
                );
            }
        }

        $key = 'tendril:retention:'.sha1(json_encode([$period, $scope->cacheKey(), $this->retentionCohort, array_keys($members ?? []), $from->toDateString()]) ?: '');

        return cache()->remember($key, (int) config('tendril-product.report_cache_seconds', 300), fn () => app(RetentionCalculator::class)
            ->matrix($from, Instant::day(Instant::now()), $scope, $period, $periods, $members));
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function formSchema(bool $withKey): array
    {
        return array_values(array_filter([
            $withKey ? TextInput::make('key')->required()->maxLength(100)->regex('/^[a-z0-9][a-z0-9_.\-]*$/') : null,
            TextInput::make('name')->required()->maxLength(191),
            Select::make('match')->options(['all' => 'All conditions', 'any' => 'Any condition'])->default('all')->required(),
            Repeater::make('conditions')
                ->schema([
                    Select::make('type')->options(array_combine(CohortDefinition::TYPES, CohortDefinition::TYPES))->required(),
                    TextInput::make('event')->helperText('performed / not_performed'),
                    TextInput::make('feature')->helperText('used_feature'),
                    TextInput::make('value')->helperText('actor_type'),
                    TextInput::make('min_count')->numeric()->default(1),
                    TextInput::make('min_days')->numeric()->default(1),
                    TextInput::make('within_days')->numeric()->default(30)->required(),
                ])
                ->minItems(1)
                ->maxItems(10)
                ->defaultItems(1)
                ->columns(4),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function definitionFrom(array $data): CohortDefinition
    {
        $conditions = [];

        foreach ((array) ($data['conditions'] ?? []) as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $conditions[] = array_filter([
                'type' => $condition['type'] ?? null,
                'event' => $condition['event'] ?? null,
                'feature' => $condition['feature'] ?? null,
                'value' => $condition['value'] ?? null,
                'min_count' => isset($condition['min_count']) ? (int) $condition['min_count'] : null,
                'min_days' => isset($condition['min_days']) ? (int) $condition['min_days'] : null,
                'within_days' => isset($condition['within_days']) ? (int) $condition['within_days'] : null,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return CohortDefinition::fromArray([
            'key' => (string) ($data['key'] ?? ''),
            'name' => (string) ($data['name'] ?? ''),
            'definition' => ['match' => (string) ($data['match'] ?? 'all'), 'conditions' => $conditions],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveCohort(array $data): void
    {
        abort_unless(TendrilFilament::canManage(), 403);

        try {
            $definition = self::definitionFrom($data);

            Cohort::query()->updateOrCreate(['key' => $definition->key], [
                'name' => $definition->name,
                'definition' => $definition->definition(),
                'active' => true,
            ]);

            Notification::make()->title('Cohort saved')->success()->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Invalid cohort')->body($e->getMessage())->danger()->send();
        }
    }
}
