<?php

namespace VitisStudio\Tendril\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use VitisStudio\Tendril\Filament\Support\PredicateForm;
use VitisStudio\Tendril\Filament\Support\TendrilFilament;
use VitisStudio\Tendril\Product\Funnels\FunnelDefinition;
use VitisStudio\Tendril\Product\Funnels\FunnelEngine;
use VitisStudio\Tendril\Product\Models\Funnel;

/**
 * Funnel builder (ordered steps with optional predicates and a window) and
 * historical results: step counts, drop-off, completion and time-to-convert.
 */
class Funnels extends TendrilPage implements HasTable
{
    use InteractsWithTable;

    #[Url]
    public ?string $funnel = null;

    public static function tendrilKey(): string
    {
        return 'funnels';
    }

    public static function tendrilLabel(): string
    {
        return 'Funnels';
    }

    public static function tendrilIcon(): string
    {
        return 'heroicon-o-funnel';
    }

    public static function tendrilSort(): int
    {
        return 5;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New funnel')
                ->icon('heroicon-o-plus')
                ->visible(fn () => TendrilFilament::canManage())
                ->schema(self::formSchema(true))
                ->action(function (array $data): void {
                    $this->saveFunnel($data);
                }),
            ...parent::getHeaderActions(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Funnel::query()->orderBy('key'))
            ->columns([
                TextColumn::make('key')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('steps')
                    ->label('Steps')
                    ->state(fn (Funnel $record) => implode(' → ', array_map(
                        fn ($s) => is_array($s) ? (string) ($s['label'] ?? $s['event'] ?? '?') : '?',
                        (array) ($record->definition['steps'] ?? []),
                    ))),
                TextColumn::make('window')
                    ->label('Window')
                    ->state(fn (Funnel $record) => round(((int) ($record->definition['window_seconds'] ?? 0)) / 3600, 1).' h'),
            ])
            ->recordUrl(fn (Funnel $record) => static::getUrl(['funnel' => $record->key]))
            ->recordActions([
                Action::make('edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn () => TendrilFilament::canManage())
                    ->fillForm(fn (Funnel $record) => self::formData($record))
                    ->schema(self::formSchema(false))
                    ->action(function (Funnel $record, array $data): void {
                        $this->saveFunnel(['key' => $record->key] + $data);
                    }),
                Action::make('delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn () => TendrilFilament::canManage())
                    ->action(function (Funnel $record): void {
                        abort_unless(TendrilFilament::canManage(), 403);
                        $record->delete();
                    }),
            ])
            ->paginated([10, 25]);
    }

    /**
     * @return array{definition: FunnelDefinition, result: array<string, mixed>}|null
     */
    public function results(): ?array
    {
        if ($this->funnel === null) {
            return null;
        }

        $model = Funnel::query()->where('key', $this->funnel)->first();

        if ($model === null) {
            return null;
        }

        $definition = FunnelDefinition::fromArray(['key' => $model->key, 'name' => $model->name, 'definition' => $model->definition]);
        $scope = $this->tenantScope();
        $key = 'tendril:funnel:'.sha1(json_encode([$definition, $scope->cacheKey(), $this->days, $this->rangeStart()->toDateString()]) ?: '');

        $result = cache()->remember($key, (int) config('tendril-product.report_cache_seconds', 300), fn () => app(FunnelEngine::class)
            ->evaluate($definition, $this->rangeStart(), $this->rangeEnd()->addDay(), $scope)
            ->jsonSerialize());

        return ['definition' => $definition, 'result' => $result];
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function formSchema(bool $withKey): array
    {
        return array_values(array_filter([
            $withKey ? TextInput::make('key')->required()->maxLength(100)->regex('/^[a-z0-9][a-z0-9_.\-]*$/') : null,
            TextInput::make('name')->required()->maxLength(191),
            TextInput::make('window_hours')->label('Conversion window (hours)')->numeric()->minValue(1)->maxValue(8784)->default(24)->required(),
            Repeater::make('steps')
                ->schema([
                    TextInput::make('event')->required()->placeholder('signup.completed'),
                    TextInput::make('label'),
                    PredicateForm::repeater('conditions'),
                ])
                ->minItems(2)
                ->maxItems(10)
                ->defaultItems(2),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public static function formData(Funnel $record): array
    {
        $definition = (array) $record->definition;

        return [
            'key' => $record->key,
            'name' => $record->name,
            'window_hours' => round(((int) ($definition['window_seconds'] ?? 86400)) / 3600, 2),
            'steps' => array_map(fn ($step) => [
                'event' => $step['event'] ?? '',
                'label' => $step['label'] ?? null,
                'conditions' => PredicateForm::fromPredicate(is_array($step['where'] ?? null) ? $step['where'] : null),
            ], (array) ($definition['steps'] ?? [])),
        ];
    }

    /**
     * Serialize the form into a validated funnel definition.
     *
     * @param  array<string, mixed>  $data
     */
    public static function definitionFrom(array $data): FunnelDefinition
    {
        $steps = [];

        foreach ((array) ($data['steps'] ?? []) as $step) {
            $steps[] = [
                'event' => (string) ($step['event'] ?? ''),
                'label' => $step['label'] ?? null,
                'where' => PredicateForm::toPredicate(is_array($step['conditions'] ?? null) ? $step['conditions'] : null),
            ];
        }

        return FunnelDefinition::fromArray([
            'key' => (string) ($data['key'] ?? ''),
            'name' => (string) ($data['name'] ?? ''),
            'definition' => [
                'steps' => $steps,
                'window_seconds' => (int) round(((float) ($data['window_hours'] ?? 24)) * 3600),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveFunnel(array $data): void
    {
        abort_unless(TendrilFilament::canManage(), 403);

        try {
            $definition = self::definitionFrom($data);

            Funnel::query()->updateOrCreate(['key' => $definition->key], [
                'name' => $definition->name,
                'definition' => $definition->definition(),
                'active' => true,
            ]);

            Notification::make()->title('Funnel saved')->success()->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Invalid funnel')->body($e->getMessage())->danger()->send();
        }
    }
}
