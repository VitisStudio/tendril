<?php

namespace VitisStudio\Tendril\Product\Funnels;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Identity\AliasResolver;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Evaluates funnels historically over raw events, reading only the step
 * events (index on name, occurred_at). Subjects are actors, alias-resolved
 * anonymous visitors, or anonymous visitors.
 *
 * Semantics: a subject enters on any step-1 event in [from, to). From each
 * entry, later steps are matched greedily (earliest qualifying event strictly
 * after the previous step, ordered by (occurred_at, id)) within the window
 * measured from the entry. The subject's result is its best attempt
 * (furthest step; earliest entry on ties).
 */
final class FunnelEngine
{
    public function __construct(private readonly Database $db) {}

    public function evaluate(FunnelDefinition $funnel, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, bool $includeAnonymous = true): FunnelResult
    {
        $aliases = AliasResolver::make();
        $windowMicros = $funnel->windowSeconds * 1_000_000;
        $raw = [];

        foreach ($funnel->steps as $index => $step) {
            $end = $index === 0 ? $to : $to->addSeconds($funnel->windowSeconds);

            $query = $scope->applyToRaw($this->db->table(Tables::EVENTS))
                ->select(['id', 'actor_type', 'actor_id', 'anonymous_id', 'occurred_at'])
                ->where('name', $step['event'])
                ->where('occurred_at', '>=', $this->db->time($from))
                ->where('occurred_at', '<', $this->db->time($end))
                ->orderBy('occurred_at')
                ->orderBy('id');

            $step['where']->apply($query);

            foreach ($query->cursor() as $row) {
                $raw[] = [$index, $row];
            }
        }

        $anonymous = [];

        foreach ($raw as [, $row]) {
            if (($row->actor_id === null || $row->actor_id === '') && is_string($row->anonymous_id)) {
                $anonymous[$row->anonymous_id] = true;
            }
        }

        $aliases->load(array_keys($anonymous));

        /** @var array<string, array<int, list<array{0: int, 1: string}>>> $bySubject */
        $bySubject = [];

        foreach ($raw as [$index, $row]) {
            $at = Instant::parse($row->occurred_at);
            $subject = $aliases->subject(
                is_string($row->actor_type) ? $row->actor_type : null,
                is_scalar($row->actor_id) ? (string) $row->actor_id : null,
                is_string($row->anonymous_id) ? $row->anonymous_id : null,
                $at,
            );

            if ($subject === null || (! $includeAnonymous && $subject->isAnonymous())) {
                continue;
            }

            $bySubject[$subject->key()][$index][] = [AliasResolver::micros($at), (string) $row->id];
        }

        $stepCount = count($funnel->steps);
        $reachedCounts = array_fill(0, $stepCount, 0);
        $durations = [];

        foreach ($bySubject as $events) {
            foreach ($events as &$list) {
                usort($list, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            }
            unset($list);

            [$reached, $duration] = self::best($events, $stepCount, $windowMicros, AliasResolver::micros($to));

            for ($k = 0; $k < $reached; $k++) {
                $reachedCounts[$k]++;
            }

            if ($reached === $stepCount && $duration !== null) {
                $durations[] = $duration / 1_000_000;
            }
        }

        $steps = [];

        foreach ($funnel->steps as $k => $step) {
            $previous = $k === 0 ? $reachedCounts[0] : $reachedCounts[$k - 1];

            $steps[] = [
                'label' => $step['label'],
                'event' => $step['event'],
                'count' => $reachedCounts[$k],
                'from_previous' => $previous > 0 ? round($reachedCounts[$k] / $previous, 4) : 0.0,
                'from_start' => $reachedCounts[0] > 0 ? round($reachedCounts[$k] / $reachedCounts[0], 4) : 0.0,
            ];
        }

        sort($durations);

        return new FunnelResult(
            $steps,
            $reachedCounts[0],
            $reachedCounts[$stepCount - 1],
            self::median($durations),
            $durations !== [] ? round(array_sum($durations) / count($durations), 3) : null,
            $funnel->windowSeconds,
            Instant::serialize($from),
            Instant::serialize($to),
        );
    }

    /**
     * @param  array<int, list<array{0: int, 1: string}>>  $events  step index => sorted [micros, id]
     * @return array{0: int, 1: ?int} [steps reached, micros from entry to last step]
     */
    public static function best(array $events, int $stepCount, int $windowMicros, int $entryBefore): array
    {
        $bestReached = 0;
        $bestDuration = null;

        foreach ($events[0] ?? [] as $entry) {
            if ($entry[0] >= $entryBefore) {
                break;
            }

            $cursor = $entry;
            $reached = 1;
            $deadline = $entry[0] + $windowMicros;

            for ($k = 1; $k < $stepCount; $k++) {
                $next = null;

                foreach ($events[$k] ?? [] as $candidate) {
                    if (($candidate[0] > $cursor[0] || ($candidate[0] === $cursor[0] && strcmp($candidate[1], $cursor[1]) > 0))
                        && $candidate[1] !== $cursor[1]) {
                        $next = $candidate;

                        break;
                    }
                }

                if ($next === null || $next[0] > $deadline) {
                    break;
                }

                $cursor = $next;
                $reached++;
            }

            if ($reached > $bestReached) {
                $bestReached = $reached;
                $bestDuration = $reached === $stepCount ? $cursor[0] - $entry[0] : null;
            }

            if ($bestReached === $stepCount) {
                break;
            }
        }

        return [$bestReached, $bestDuration];
    }

    /**
     * @param  list<float>  $sorted
     */
    private static function median(array $sorted): ?float
    {
        $n = count($sorted);

        if ($n === 0) {
            return null;
        }

        $mid = intdiv($n, 2);

        return round($n % 2 === 1 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2, 3);
    }
}
