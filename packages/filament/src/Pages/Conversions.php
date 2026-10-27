<?php

namespace VitisStudio\Tendril\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Filament\Support\PredicateForm;
use VitisStudio\Tendril\Filament\Support\TendrilFilament;
use VitisStudio\Tendril\Filament\Widgets\ConversionTrendChart;
use VitisStudio\Tendril\Marketing\Conversions\ConversionDefinition;
use VitisStudio\Tendril\Marketing\Conversions\ConversionEvaluator;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRegistry;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRepository;
use VitisStudio\Tendril\Marketing\Models\Conversion;
use VitisStudio\Tendril\Marketing\Money\Money;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;

/**
 * Data and code conversion definitions with historical preview and totals
 * from conversion rollups.
 */
class Conversions extends TendrilPage implements HasTable
{
    use InteractsWithTable;

    public static function tendrilKey(): string
    {
        return 'conversions';
    }

    public static function tendrilLabel(): string
    {
        return 'Conversions';
    }

    public static function tendrilIcon(): string
    {
        return 'heroicon-o-currency-dollar';
    }

    public static function tendrilSort(): int
    {
        return 10;
    }

    protected function getHeaderWidgets(): array
    {
        return [ConversionTrendChart::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New conversion')
                ->icon('heroicon-o-plus')
                ->visible(fn () => TendrilFilament::canManage())
                ->schema(self::formSchema(true))
                ->action(function (array $data): void {
                    $this->saveConversion($data);
                }),
            Action::make('preview')
                ->label('Preview definition')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->schema(self::formSchema(true))
                ->action(function (array $data): void {
                    $this->previewConversion(self::definitionFrom($data));
                }),
            ...parent::getHeaderActions(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, int $page, int $recordsPerPage): LengthAwarePaginator => $this->conversionRecords($search, $page, $recordsPerPage))
            ->searchable()
            ->columns([
                TextColumn::make('key'),
                TextColumn::make('name'),
                TextColumn::make('event'),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'code' ? 'Code' : 'Data')
                    ->color(fn (string $state) => $state === 'code' ? 'gray' : 'primary')
                    ->tooltip(fn (array $record) => $record['source'] === 'code' ? 'Defined via Tendril::conversion(); read-only here' : null),
                TextColumn::make('total')
                    ->label('Conversions')
                    ->state(fn (array $record) => $this->totalLabel($record['key'])),
                TextColumn::make('value')
                    ->label('Value')
                    ->state(fn (array $record) => $this->valueLabel($record['key'])),
            ])
            ->recordActions([
                Action::make('preview')
                    ->icon('heroicon-o-eye')
                    ->action(function (array $record): void {
                        $conversion = app(ConversionRepository::class)->find($record['key']);

                        if ($conversion === null) {
                            Notification::make()->title('Invalid conversion')->body("Conversion [{$record['key']}] has an invalid definition.")->danger()->send();

                            return;
                        }

                        $this->previewConversion($conversion);
                    }),
                Action::make('edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (array $record) => $record['source'] === 'data' && TendrilFilament::canManage())
                    ->fillForm(fn (array $record) => self::formData(Conversion::query()->where('key', $record['key'])->firstOrFail()))
                    ->schema(self::formSchema(false))
                    ->action(function (array $record, array $data): void {
                        $this->saveConversion(['key' => $record['key']] + $data);
                    }),
                Action::make('delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (array $record) => $record['source'] === 'data' && TendrilFilament::canManage())
                    ->action(function (array $record): void {
                        abort_unless(TendrilFilament::canManage(), 403);
                        Conversion::query()->where('key', $record['key'])->delete();
                        $this->resetTable();
                    }),
            ])
            ->paginated([10, 25]);
    }

    /**
     * Stored rows are read directly rather than through the repository so a row
     * with an invalid definition still shows up and can be fixed or deleted.
     *
     * @return LengthAwarePaginator<string, array{key: string, name: string, event: string, source: string}>
     */
    public function conversionRecords(?string $search, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $records = [];

        foreach (Conversion::query()->get() as $row) {
            $records[$row->key] = ['key' => $row->key, 'name' => $row->name, 'event' => (string) ($row->definition['event'] ?? ''), 'source' => 'data'];
        }

        foreach (app(ConversionRegistry::class)->all() as $key => $conversion) {
            $records[$key] = ['key' => $key, 'name' => $conversion->name, 'event' => $conversion->event, 'source' => 'code'];
        }

        $matches = collect($records)
            ->when(filled($search), fn (Collection $c) => $c->filter(fn (array $r) => Str::contains($r['key'].' '.$r['name'].' '.$r['event'], (string) $search, ignoreCase: true)))
            ->sortKeys();

        return new LengthAwarePaginator($matches->forPage($page, $recordsPerPage), $matches->count(), $recordsPerPage, $page);
    }

    public function totalLabel(string $key): string
    {
        return number_format(app(ProductMetrics::class)->rollupTotal('conversion', $key, $this->rangeStart(), $this->rangeEnd(), $this->tenantScope()));
    }

    public function valueLabel(string $key): string
    {
        $metrics = app(ProductMetrics::class);
        $rows = $metrics->rollups('conversion', null, $this->rangeStart(), $this->rangeEnd(), $this->tenantScope())
            ->where('dimension', 'like', $key.':%')
            ->select('dimension')
            ->selectRaw('sum('.app(\VitisStudio\Tendril\Core\Support\Database::class)->wrap('sum').') as total')
            ->groupBy('dimension')
            ->get();

        $parts = [];

        foreach ($rows as $row) {
            // LIKE treats "_" as a wildcard; keep exact prefixes only.
            if (! str_starts_with((string) $row->dimension, $key.':')) {
                continue;
            }

            $currency = substr((string) $row->dimension, strlen($key) + 1);
            $parts[] = Money::format((int) $row->total, $currency);
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function formSchema(bool $withKey): array
    {
        return array_values(array_filter([
            $withKey ? TextInput::make('key')->required()->maxLength(100)->regex(ConversionDefinition::KEY_PATTERN) : null,
            TextInput::make('name')->required()->maxLength(191),
            TextInput::make('event')->required()->placeholder('order.completed'),
            PredicateForm::repeater('conditions'),
            TextInput::make('value_path')->placeholder('properties.revenue')->helperText('Optional numeric property holding the value.'),
            Toggle::make('value_in_minor_units')->label('Value is already in minor units (cents)'),
            TextInput::make('currency')->maxLength(3)->placeholder('USD'),
            TextInput::make('currency_path')->placeholder('properties.currency'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public static function formData(Conversion $record): array
    {
        $d = (array) $record->definition;

        return [
            'key' => $record->key,
            'name' => $record->name,
            'event' => $d['event'] ?? '',
            'conditions' => PredicateForm::fromPredicate(is_array($d['where'] ?? null) ? $d['where'] : null),
            'value_path' => $d['value_path'] ?? null,
            'value_in_minor_units' => (bool) ($d['value_in_minor_units'] ?? false),
            'currency' => $d['currency'] ?? null,
            'currency_path' => $d['currency_path'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function definitionFrom(array $data): ConversionDefinition
    {
        return ConversionDefinition::fromArray([
            'key' => (string) ($data['key'] ?? ''),
            'name' => (string) ($data['name'] ?? ''),
            'definition' => [
                'event' => strtolower(trim((string) ($data['event'] ?? ''))),
                'where' => PredicateForm::toPredicate(is_array($data['conditions'] ?? null) ? $data['conditions'] : null),
                'value_path' => $data['value_path'] ?? null,
                'value_in_minor_units' => (bool) ($data['value_in_minor_units'] ?? false),
                'currency' => $data['currency'] ?? null,
                'currency_path' => $data['currency_path'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveConversion(array $data): void
    {
        abort_unless(TendrilFilament::canManage(), 403);

        try {
            app(ConversionRepository::class)->save(self::definitionFrom($data));
            $this->resetTable();
            Notification::make()->title('Conversion saved')->body('History will be re-evaluated on the next aggregation run.')->success()->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Invalid conversion')->body($e->getMessage())->danger()->send();
        }
    }

    protected function previewConversion(ConversionDefinition $conversion): void
    {
        $summary = app(ConversionEvaluator::class)->summary($conversion, Instant::now()->subDays(30), Instant::now(), $this->tenantScope());
        $values = [];

        foreach ($summary['values'] as $currency => $minor) {
            $values[] = Money::format($minor, $currency);
        }

        Notification::make()
            ->title('Historical preview (30 days)')
            ->body("{$summary['conversions']} conversions by {$summary['converters']} converters".($values !== [] ? ' · '.implode(', ', $values) : '').'.')
            ->info()
            ->send();
    }
}
