<?php

namespace VitisStudio\Tendril\Product\Features;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Identity\AliasResolver;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Historical preview of an unsaved feature definition: evaluates the
 * definition against raw facts for a short window (admin-triggered only,
 * never on dashboards), using the same matching as aggregation.
 */
final class FeaturePreview
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return array{uniques: int, events: int, days: int}
     */
    public function preview(FeatureDefinition $feature, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): array
    {
        $json = $this->db->json();
        $route = $json->text('context', ['route']);
        $component = $json->text('context', ['component']);
        $action = $json->text('context', ['action']);
        $columns = ['actor_type', 'actor_id', 'anonymous_id', 'name'];
        $pageView = (string) config('tendril-product.page_view_event', 'page.viewed');

        $query = $scope->applyToRaw($this->db->table(Tables::EVENTS))
            ->select($columns)
            ->selectRaw("{$route} as ctx_route, {$component} as ctx_component, {$action} as ctx_action, count(*) as aggregate, min(".$this->db->wrap('occurred_at').') as first_at')
            ->where('occurred_at', '>=', $this->db->time(Instant::day($from)))
            ->where('occurred_at', '<', $this->db->time(Instant::day($to)->addDay()))
            ->groupBy($columns)
            ->groupByRaw("{$route}, {$component}, {$action}");

        if ($feature->routes === [] && $feature->components === [] && $feature->events !== [] && ! $this->hasWildcards($feature->events)) {
            $query->whereIn('name', $feature->events);
        }

        $aliases = AliasResolver::make();
        $subjects = [];
        $events = 0;

        foreach ($query->get() as $row) {
            if (! $feature->matches((string) $row->name, $this->str($row->ctx_route), $this->str($row->ctx_component), $this->str($row->ctx_action), $pageView)) {
                continue;
            }

            $events += (int) $row->aggregate;
            $subject = $aliases->subject(
                is_string($row->actor_type) ? $row->actor_type : null,
                is_scalar($row->actor_id) ? (string) $row->actor_id : null,
                is_string($row->anonymous_id) ? $row->anonymous_id : null,
                Instant::parse($row->first_at),
            );

            if ($subject !== null && ! $subject->isAnonymous()) {
                $subjects[$subject->key()] = true;
            }
        }

        return ['uniques' => count($subjects), 'events' => $events, 'days' => (int) Instant::day($from)->diffInDays(Instant::day($to)) + 1];
    }

    /**
     * @param  list<string>  $patterns
     */
    private function hasWildcards(array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '*')) {
                return true;
            }
        }

        return false;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
