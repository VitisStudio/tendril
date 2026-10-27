<?php

namespace VitisStudio\Tendril\Product\Aggregation\Processors;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Product\Aggregation\AggregationContext;
use VitisStudio\Tendril\Product\Aggregation\BucketProcessor;

/**
 * Actor-activity rows (tenant, actor, date, kind, subject) — the only source
 * of distinct counts. Anonymous events are attributed through alias ranges;
 * a visitor whose day straddles a range boundary is resolved event by event.
 */
final class ActivityProcessor implements BucketProcessor
{
    public const array KINDS = ['any', 'event', 'feature'];

    public function key(): string
    {
        return 'activity';
    }

    public function processHour(CarbonImmutable $hour, AggregationContext $context): void
    {
        // Activity is day-grained.
    }

    public function processDay(CarbonImmutable $day, AggregationContext $context): void
    {
        $groups = $context->dayGroups($day);
        $aliases = $context->aliases;

        $window = [];

        foreach ($groups as $group) {
            if (($group->actor_id === null || $group->actor_id === '') && is_string($group->anonymous_id) && $group->anonymous_id !== '') {
                $first = Instant::parse($group->first_at);
                $last = Instant::parse($group->last_at);
                $current = $window[$group->anonymous_id] ?? [$first, $last];
                $window[$group->anonymous_id] = [
                    $first->lessThan($current[0]) ? $first : $current[0],
                    $last->greaterThan($current[1]) ? $last : $current[1],
                ];
            }
        }

        $aliases->load(array_keys($window));

        $straddling = [];

        foreach ($window as $anonymousId => [$first, $last]) {
            if (! $aliases->isDeterminate((string) $anonymousId, $first, $last)) {
                $straddling[(string) $anonymousId] = true;
            }
        }

        $rows = [];

        foreach ($groups as $group) {
            $anonymousId = is_string($group->anonymous_id) ? $group->anonymous_id : null;

            if (($group->actor_id === null || $group->actor_id === '') && $anonymousId !== null && isset($straddling[$anonymousId])) {
                continue;
            }

            $subject = $aliases->subject(
                is_string($group->actor_type) ? $group->actor_type : null,
                is_scalar($group->actor_id) ? (string) $group->actor_id : null,
                $anonymousId,
                Instant::parse($group->first_at),
            );

            if ($subject !== null) {
                $this->add($rows, $context, $subject, $group->tenant_type, $group->tenant_id, (string) $group->name,
                    EventRollupProcessor::str($group->ctx_route), EventRollupProcessor::str($group->ctx_component), EventRollupProcessor::str($group->ctx_action), (int) $group->aggregate);
            }
        }

        foreach (array_keys($straddling) as $anonymousId) {
            $this->addIndividually($rows, $context, $day, $anonymousId);
        }

        $this->write($context, $day, $rows);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function addIndividually(array &$rows, AggregationContext $context, CarbonImmutable $day, string $anonymousId): void
    {
        $db = $context->db;
        $json = $db->json();

        $events = $db->table(Tables::EVENTS)
            ->select(['tenant_type', 'tenant_id', 'name', 'occurred_at'])
            ->selectRaw($json->text('context', ['route']).' as ctx_route, '.$json->text('context', ['component']).' as ctx_component, '.$json->text('context', ['action']).' as ctx_action')
            ->where('anonymous_id', $anonymousId)
            ->whereNull('actor_id')
            ->where('occurred_at', '>=', $db->time($day))
            ->where('occurred_at', '<', $db->time($day->addDay()))
            ->get();

        foreach ($events as $event) {
            $subject = $context->aliases->subject(null, null, $anonymousId, Instant::parse($event->occurred_at));

            if ($subject !== null) {
                $this->add($rows, $context, $subject, $event->tenant_type, $event->tenant_id, (string) $event->name,
                    EventRollupProcessor::str($event->ctx_route), EventRollupProcessor::str($event->ctx_component), EventRollupProcessor::str($event->ctx_action), 1);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function add(array &$rows, AggregationContext $context, ActorRef $subject, mixed $tenantType, mixed $tenantId, string $name, ?string $route, ?string $component, ?string $action, int $count): void
    {
        $tenantType = (string) $tenantType;
        $tenantId = (string) $tenantId;

        $this->bump($rows, $tenantType, $tenantId, $subject, 'any', '', $count);
        $this->bump($rows, $tenantType, $tenantId, $subject, 'event', $name, $count);

        foreach ($context->featuresFor($name, $route, $component, $action) as $feature) {
            $this->bump($rows, $tenantType, $tenantId, $subject, 'feature', $feature, $count);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function bump(array &$rows, string $tenantType, string $tenantId, ActorRef $subject, string $kind, string $name, int $count): void
    {
        $key = implode("\0", [$tenantType, $tenantId, $kind, $name, $subject->type, $subject->id]);

        if (! isset($rows[$key])) {
            $rows[$key] = [
                'tenant_type' => $tenantType,
                'tenant_id' => $tenantId,
                'actor_type' => $subject->type,
                'actor_id' => $subject->id,
                'kind' => $kind,
                'subject' => mb_substr($name, 0, 191),
                'event_count' => 0,
            ];
        }

        $rows[$key]['event_count'] += $count;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function write(AggregationContext $context, CarbonImmutable $day, array $rows): void
    {
        $db = $context->db;
        $date = $day->toDateString();

        $db->table(Tables::ACTIVITY)->where('date', $date)->whereIn('kind', self::KINDS)->delete();

        $payload = array_map(fn (array $row) => ['date' => $date] + $row, array_values($rows));

        foreach (array_chunk($payload, 500) as $chunk) {
            $db->table(Tables::ACTIVITY)->insert($chunk);
        }
    }
}
