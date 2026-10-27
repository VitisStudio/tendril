<?php

namespace VitisStudio\Tendril\Filament\Pages;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Url;
use stdClass;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Product\Models\ActorActivity;

/**
 * Actor directory (activity table) and, for a selected actor, an activity
 * timeline, sessions and features used — all tenant scoped.
 */
class Users extends TendrilPage implements HasTable
{
    use InteractsWithTable;

    #[Url]
    public ?string $actor = null;

    public static function tendrilKey(): string
    {
        return 'users';
    }

    public static function tendrilLabel(): string
    {
        return 'Users';
    }

    public static function tendrilIcon(): string
    {
        return 'heroicon-o-users';
    }

    public static function tendrilSort(): int
    {
        return 2;
    }

    public function table(Table $table): Table
    {
        $db = app(Database::class);
        $key = $db->concat($db->wrap('actor_type'), "'|'", $db->wrap('actor_id'));
        $date = $db->wrap('date');
        $count = $db->wrap('event_count');

        return $table
            ->query(fn () => $this->tenantScope()->applyToDerived(ActorActivity::query())
                ->selectRaw("{$key} as id, actor_type, actor_id, max({$date}) as last_seen, count(*) as active_days, sum({$count}) as events")
                ->where('kind', 'any')
                ->where('actor_type', '<>', ActorRef::ANONYMOUS)
                ->where('date', '>=', $this->rangeStart()->toDateString())
                ->groupBy(['actor_type', 'actor_id'])
                ->orderByDesc('last_seen'))
            ->columns([
                TextColumn::make('actor_type')->label('Type')->formatStateUsing(fn ($state) => class_basename((string) $state)),
                TextColumn::make('actor_id')->label('Actor'),
                TextColumn::make('last_seen')->label('Last seen')->date(),
                TextColumn::make('active_days')->label('Active days')->numeric(),
                TextColumn::make('events')->label('Events')->numeric(),
            ])
            ->recordUrl(fn ($record) => static::getUrl(['actor' => $record->actor_type.'|'.$record->actor_id]))
            ->defaultKeySort(false)
            ->paginated([10, 25, 50]);
    }

    /**
     * Timeline for the selected actor, only if the actor has activity inside
     * the current tenant scope (a crafted ?actor= cannot cross tenants).
     *
     * @return array{actor: ActorRef, events: array<int, stdClass>, sessions: array<int, stdClass>, features: list<string>, last_seen: ?string}|null
     */
    public function timeline(): ?array
    {
        if ($this->actor === null || ! str_contains($this->actor, '|')) {
            return null;
        }

        [$type, $id] = explode('|', $this->actor, 2);
        $actor = ActorRef::tryFrom(['type' => $type, 'id' => $id]);

        if ($actor === null || $actor->isAnonymous()) {
            return null;
        }

        $db = app(Database::class);
        $scope = $this->tenantScope();

        $activity = $scope->applyToDerived($db->table(Tables::ACTIVITY))
            ->where('actor_type', $actor->type)
            ->where('actor_id', $actor->id);

        if (! (clone $activity)->exists()) {
            return null;
        }

        $events = $scope->applyToRaw($db->table(Tables::EVENTS))
            ->where('actor_type', $actor->type)
            ->where('actor_id', $actor->id)
            ->where('occurred_at', '>=', $db->time(Instant::now()->subDays(90)))
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get(['id', 'name', 'occurred_at', 'source', 'context'])
            ->all();

        $sessions = $scope->applyToRaw($db->table(Tables::SESSIONS))
            ->where('actor_type', $actor->type)
            ->where('actor_id', $actor->id)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get(['id', 'started_at', 'last_seen_at', 'landing_route', 'utm_source', 'utm_medium', 'utm_campaign', 'event_count', 'page_view_count'])
            ->all();

        $features = array_values((clone $activity)
            ->where('kind', 'feature')
            ->where('date', '>=', $this->rangeStart()->toDateString())
            ->distinct()
            ->orderBy('subject')
            ->pluck('subject')
            ->map(fn ($s) => (string) $s)
            ->all());

        $lastSeen = (clone $activity)->where('kind', 'any')->max('date');

        return [
            'actor' => $actor,
            'events' => $events,
            'sessions' => $sessions,
            'features' => $features,
            'last_seen' => is_string($lastSeen) ? substr($lastSeen, 0, 10) : null,
        ];
    }
}
