<?php

namespace VitisStudio\Tendril\Filament\Pages;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Url;
use VitisStudio\Tendril\Core\Diagnostics\EventInspector;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Filament\Widgets\EventVolumeChart;
use VitisStudio\Tendril\Product\Models\EventCatalogEntry;

/**
 * Event catalog (daily rollups) with a per-event volume chart and a
 * version/source/property-schema inspector for the selected event.
 */
class Events extends TendrilPage implements HasTable
{
    use InteractsWithTable;

    #[Url]
    public ?string $event = null;

    public static function tendrilKey(): string
    {
        return 'events';
    }

    public static function tendrilLabel(): string
    {
        return 'Events';
    }

    public static function tendrilIcon(): string
    {
        return 'heroicon-o-bolt';
    }

    public static function tendrilSort(): int
    {
        return 4;
    }

    protected function getHeaderWidgets(): array
    {
        return [EventVolumeChart::class];
    }

    public function getWidgetData(): array
    {
        return ['days' => $this->days, 'eventName' => $this->event];
    }

    public function table(Table $table): Table
    {
        $db = app(Database::class);
        $count = $db->wrap('count');
        $bucket = $db->wrap('bucket_start');
        $dimension = $db->wrap('dimension');

        return $table
            ->query(fn () => $this->tenantScope()->applyToDerived(EventCatalogEntry::query())
                ->selectRaw("{$dimension} as id, {$dimension} as event_name, sum({$count}) as total, max({$bucket}) as last_bucket")
                ->where('granularity', 'day')
                ->where('kind', 'event')
                ->where('bucket_start', '>=', $db->time($this->rangeStart()))
                ->groupBy('dimension')
                ->orderByDesc('total'))
            ->columns([
                TextColumn::make('event_name')->label('Event'),
                TextColumn::make('total')->label("Events ({$this->days} days)")->numeric(),
                TextColumn::make('last_bucket')->label('Last day')->formatStateUsing(fn ($state) => Instant::tryParse($state)?->toDateString() ?? '—'),
            ])
            ->recordUrl(fn ($record) => static::getUrl(['event' => $record->event_name]))
            ->defaultKeySort(false)
            ->paginated([10, 25, 50]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function inspection(): ?array
    {
        if ($this->event === null || $this->event === '') {
            return null;
        }

        return app(EventInspector::class)->inspect($this->event, max(1, $this->days), 500, $this->tenantScope());
    }
}
