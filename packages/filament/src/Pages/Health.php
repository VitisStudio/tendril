<?php

namespace VitisStudio\Tendril\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use VitisStudio\Tendril\Core\Diagnostics\CheckResult;
use VitisStudio\Tendril\Core\Diagnostics\Diagnostics;
use VitisStudio\Tendril\Core\Models\DeadLetter;
use VitisStudio\Tendril\Core\Storage\DeadLetters;
use VitisStudio\Tendril\Core\Storage\IngestionStats;
use VitisStudio\Tendril\Filament\Support\TendrilFilament;

/**
 * Ingestion, queue/aggregation lag, storage, cardinality and failures: the
 * same checks as tendril:doctor, plus dead letters. Operational data is
 * global (not tenant data), so this page follows the manage ability.
 */
class Health extends TendrilPage implements HasTable
{
    use InteractsWithTable;

    public static function tendrilKey(): string
    {
        return 'health';
    }

    public static function tendrilLabel(): string
    {
        return 'Health';
    }

    public static function tendrilIcon(): string
    {
        return 'heroicon-o-heart';
    }

    public static function tendrilSort(): int
    {
        return 8;
    }

    public static function canAccess(): bool
    {
        return TendrilFilament::canManage();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->label('Retry dead letters')
                ->icon('heroicon-o-arrow-uturn-right')
                ->requiresConfirmation()
                ->action(function (): void {
                    abort_unless(TendrilFilament::canManage(), 403);
                    [$retried, $failed] = app(DeadLetters::class)->retry(100);
                    Notification::make()->title("Retried {$retried}, still failing {$failed}")->success()->send();
                }),
            ...parent::getHeaderActions(),
        ];
    }

    /**
     * @return list<CheckResult>
     */
    public function checks(): array
    {
        return app(Diagnostics::class)->run();
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return app(IngestionStats::class)->summary(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => DeadLetter::query()->orderByDesc('failed_at'))
            ->heading('Dead letters')
            ->columns([
                TextColumn::make('failed_at')->label('Failed at')->dateTime(),
                TextColumn::make('events')->numeric(),
                TextColumn::make('attempts')->numeric(),
                TextColumn::make('reason')->limit(120)->wrap(),
            ])
            ->paginated([10, 25]);
    }
}
