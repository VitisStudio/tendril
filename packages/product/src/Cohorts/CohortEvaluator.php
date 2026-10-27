<?php

namespace VitisStudio\Tendril\Product\Cohorts;

use Carbon\CarbonImmutable;
use stdClass;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Identity\AliasResolver;
use VitisStudio\Tendril\Core\Query\Predicate;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Evaluates cohorts as of a date. Behavioral conditions without property
 * predicates read the actor-activity table; conditions with predicates read
 * the matching raw events only (bounded by name + window) and resolve
 * anonymous events through alias ranges. Members are known actors only.
 */
final class CohortEvaluator
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return array<string, ActorRef> actor key => actor
     */
    public function members(CohortDefinition $cohort, CarbonImmutable $asOf, TenantScope $scope): array
    {
        $result = null;

        foreach ($cohort->conditions as $condition) {
            $set = $this->condition($condition, Instant::day($asOf), $scope);

            if ($result === null) {
                $result = $set;
            } elseif ($cohort->match === 'all') {
                $result = array_intersect_key($result, $set);
            } else {
                $result += $set;
            }
        }

        $result ??= [];
        ksort($result);

        return $result;
    }

    public function size(CohortDefinition $cohort, CarbonImmutable $asOf, TenantScope $scope): int
    {
        return count($this->members($cohort, $asOf, $scope));
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, ActorRef>
     */
    private function condition(array $condition, CarbonImmutable $asOf, TenantScope $scope): array
    {
        $within = (int) $condition['within_days'];
        $from = $asOf->subDays($within - 1);

        return match ($condition['type']) {
            'performed' => isset($condition['where'])
                ? $this->performedWithPredicate((string) $condition['event'], Predicate::fromArray($condition['where']), (int) $condition['min_count'], $from, $asOf, $scope)
                : $this->performed((string) $condition['event'], (int) $condition['min_count'], $from, $asOf, $scope),
            'not_performed' => array_diff_key(
                $this->active($from, $asOf, $scope),
                $this->performed((string) $condition['event'], 1, $from, $asOf, $scope),
            ),
            'used_feature' => $this->usedFeature((string) $condition['feature'], (int) $condition['min_days'], $from, $asOf, $scope),
            'first_seen' => $this->firstSeen($from, $asOf, $scope),
            'actor_type' => array_filter($this->active($from, $asOf, $scope), fn (ActorRef $a) => $a->type === $condition['value']),
            default => [],
        };
    }

    /**
     * @return array<string, ActorRef>
     */
    private function performed(string $event, int $minCount, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): array
    {
        $rows = $this->activity('event', $event, $from, $to, $scope)
            ->select(['actor_type', 'actor_id'])
            ->groupBy(['actor_type', 'actor_id'])
            ->havingRaw('sum('.$this->db->wrap('event_count').') >= ?', [$minCount])
            ->get();

        return $this->set($rows);
    }

    /**
     * @return array<string, ActorRef>
     */
    private function performedWithPredicate(string $event, Predicate $where, int $minCount, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): array
    {
        $query = $scope->applyToRaw($this->db->table(Tables::EVENTS))
            ->select(['actor_type', 'actor_id', 'anonymous_id', 'occurred_at'])
            ->where('name', $event)
            ->where('occurred_at', '>=', $this->db->time($from))
            ->where('occurred_at', '<', $this->db->time($to->addDay()));

        $where->apply($query);

        $rows = $query->get();
        $aliases = AliasResolver::make();
        $aliases->load($rows->pluck('anonymous_id')->filter(fn ($v) => is_string($v))->unique()->values()->all());

        $counts = [];
        $actors = [];

        foreach ($rows as $row) {
            $subject = $aliases->subject(
                is_string($row->actor_type) ? $row->actor_type : null,
                is_scalar($row->actor_id) ? (string) $row->actor_id : null,
                is_string($row->anonymous_id) ? $row->anonymous_id : null,
                Instant::parse($row->occurred_at),
            );

            if ($subject === null || $subject->isAnonymous()) {
                continue;
            }

            $counts[$subject->key()] = ($counts[$subject->key()] ?? 0) + 1;
            $actors[$subject->key()] = $subject;
        }

        return array_filter($actors, fn (ActorRef $a) => $counts[$a->key()] >= $minCount);
    }

    /**
     * @return array<string, ActorRef>
     */
    private function usedFeature(string $feature, int $minDays, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): array
    {
        $inner = $this->activity('feature', $feature, $from, $to, $scope)->select(['actor_type', 'actor_id', 'date'])->distinct();

        $rows = $this->db->connection()->query()->fromSub($inner, 'd')
            ->select(['actor_type', 'actor_id'])
            ->groupBy(['actor_type', 'actor_id'])
            ->havingRaw('count(*) >= ?', [$minDays])
            ->get();

        return $this->set($rows);
    }

    /**
     * @return array<string, ActorRef>
     */
    private function firstSeen(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): array
    {
        $date = $this->db->wrap('date');

        $rows = $scope->applyToDerived($this->db->table(Tables::ACTIVITY))
            ->where('kind', 'any')
            ->where('actor_type', '<>', ActorRef::ANONYMOUS)
            ->where('date', '<=', $to->toDateString())
            ->select(['actor_type', 'actor_id'])
            ->groupBy(['actor_type', 'actor_id'])
            ->havingRaw("min({$date}) >= ?", [$from->toDateString()])
            ->get();

        return $this->set($rows);
    }

    /**
     * @return array<string, ActorRef>
     */
    private function active(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): array
    {
        return $this->set($this->activity('any', '', $from, $to, $scope)->select(['actor_type', 'actor_id'])->distinct()->get());
    }

    private function activity(string $kind, string $subject, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): \Illuminate\Database\Query\Builder
    {
        return $scope->applyToDerived($this->db->table(Tables::ACTIVITY))
            ->where('kind', $kind)
            ->where('subject', $subject)
            ->where('actor_type', '<>', ActorRef::ANONYMOUS)
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<=', $to->toDateString());
    }

    /**
     * @param  iterable<stdClass>  $rows
     * @return array<string, ActorRef>
     */
    private function set(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $actor = new ActorRef((string) $row->actor_type, (string) $row->actor_id);
            $out[$actor->key()] = $actor;
        }

        return $out;
    }
}
