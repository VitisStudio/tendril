<?php

namespace VitisStudio\Tendril\Marketing\Attribution;

use Carbon\CarbonImmutable;
use stdClass;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRecord;

/**
 * Loads the compact touchpoint rows relevant to a set of conversions (never
 * raw events): by every anonymous id linked to the converter (explicit alias
 * links, so anonymous → known journeys are preserved) and by the actor.
 *
 * Tenant scope: a tenant sees touchpoints of that tenant plus tenant-less
 * touchpoints (pre-signup public visits) of its own converters only.
 */
final class TouchpointIndex
{
    /** @var array<string, list<stdClass>> anonymous id => touchpoints */
    private array $byAnonymous = [];

    /** @var array<string, list<stdClass>> actor key => touchpoints */
    private array $byActor = [];

    /** @var array<string, array<string, list<array{0: CarbonImmutable, 1: ?CarbonImmutable}>>> actor key => anonymous id => ranges */
    private array $aliases = [];

    private function __construct() {}

    /**
     * @param  list<ConversionRecord>  $records
     */
    public static function build(array $records, CarbonImmutable $from, CarbonImmutable $to, int $lookbackDays, TenantScope $scope, Database $db): self
    {
        $index = new self;
        $actors = [];
        $anonymous = [];

        foreach ($records as $record) {
            if ($record->actor !== null) {
                $actors[$record->actor->key()] = $record->actor;
            }

            if ($record->anonymousId !== null) {
                $anonymous[$record->anonymousId] = true;
            }
        }

        foreach (array_chunk(array_values($actors), 200) as $chunk) {
            $rows = $db->table(Tables::ALIASES)
                ->whereIn('actor_id', array_map(fn (ActorRef $a) => $a->id, $chunk))
                ->get(['anonymous_id', 'actor_type', 'actor_id', 'valid_from', 'valid_to']);

            foreach ($rows as $row) {
                $key = $row->actor_type.'|'.$row->actor_id;

                if (isset($actors[$key])) {
                    $index->aliases[$key][(string) $row->anonymous_id][] = [
                        Instant::parse($row->valid_from),
                        Instant::tryParse($row->valid_to),
                    ];
                    $anonymous[(string) $row->anonymous_id] = true;
                }
            }
        }

        $start = $db->time($from->subDays($lookbackDays));
        $end = $db->time($to);
        $columns = ['id', 'session_id', 'occurred_at', 'anonymous_id', 'actor_type', 'actor_id', 'source', 'medium', 'campaign', 'content', 'term', 'referrer_class', 'landing_route', 'has_click_id'];

        $scoped = function ($query) use ($scope) {
            if ($scope->mode === 'tenant' && $scope->tenant !== null) {
                $query->where(fn ($q) => $q->whereNull('tenant_type')->orWhere(fn ($q) => $q
                    ->where('tenant_type', $scope->tenant->type)
                    ->where('tenant_id', $scope->tenant->id)));
            } elseif ($scope->mode === 'none') {
                $query->whereRaw('1 = 0');
            }

            return $query;
        };

        foreach (array_chunk(array_keys($anonymous), 500) as $chunk) {
            $rows = $scoped($db->table(Tables::TOUCHPOINTS))
                ->whereIn('anonymous_id', $chunk)
                ->where('occurred_at', '>=', $start)
                ->where('occurred_at', '<', $end)
                ->get($columns);

            foreach ($rows as $row) {
                $row->at = Instant::parse($row->occurred_at);
                $index->byAnonymous[(string) $row->anonymous_id][] = $row;
            }
        }

        foreach (array_chunk(array_values($actors), 200) as $chunk) {
            $rows = $scoped($db->table(Tables::TOUCHPOINTS))
                ->whereIn('actor_id', array_map(fn (ActorRef $a) => $a->id, $chunk))
                ->where('occurred_at', '>=', $start)
                ->where('occurred_at', '<', $end)
                ->get($columns);

            foreach ($rows as $row) {
                $key = $row->actor_type.'|'.$row->actor_id;

                if (isset($actors[$key])) {
                    $row->at = Instant::parse($row->occurred_at);
                    $index->byActor[$key][] = $row;
                }
            }
        }

        return $index;
    }

    /**
     * Touchpoints within [conversion - lookback, conversion], oldest first, unique by session.
     *
     * @return list<stdClass>
     */
    public function for(ConversionRecord $record, int $lookbackDays): array
    {
        $candidates = [];
        $ranges = $record->actor !== null ? ($this->aliases[$record->actor->key()] ?? []) : [];

        if ($record->actor !== null) {
            $candidates = $this->byActor[$record->actor->key()] ?? [];
        }

        // The conversion's own visitor id always counts; other visitor ids count
        // only inside the effective alias ranges that link them to this actor.
        if ($record->anonymousId !== null && ! isset($ranges[$record->anonymousId])) {
            $candidates = [...$candidates, ...($this->byAnonymous[$record->anonymousId] ?? [])];
        }

        foreach ($ranges as $anonymousId => $windows) {
            foreach ($this->byAnonymous[$anonymousId] ?? [] as $touchpoint) {
                foreach ($windows as [$from, $to]) {
                    if ($touchpoint->at->greaterThanOrEqualTo($from) && ($to === null || $touchpoint->at->lessThan($to))) {
                        $candidates[] = $touchpoint;

                        break;
                    }
                }
            }
        }

        $earliest = $record->occurredAt->subDays($lookbackDays);
        $unique = [];

        foreach ($candidates as $touchpoint) {
            if ($touchpoint->at->lessThan($earliest) || $touchpoint->at->greaterThan($record->occurredAt)) {
                continue;
            }

            $unique[(string) $touchpoint->session_id] = $touchpoint;
        }

        $list = array_values($unique);
        usort($list, fn ($a, $b) => [$a->at->getTimestamp(), (int) $a->at->format('u'), (string) $a->id] <=> [$b->at->getTimestamp(), (int) $b->at->format('u'), (string) $b->id]);

        return $list;
    }

    /**
     * Anonymous ids that were not the conversion's own id (anonymous → known journeys).
     */
    public function hadAnonymousTouches(ConversionRecord $record, int $lookbackDays): bool
    {
        foreach ($this->for($record, $lookbackDays) as $touchpoint) {
            if ($touchpoint->actor_id === null) {
                return true;
            }
        }

        return false;
    }
}
