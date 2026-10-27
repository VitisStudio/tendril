<?php

namespace VitisStudio\Tendril\Product\Aggregation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use stdClass;
use VitisStudio\Tendril\Core\Identity\AliasResolver;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Product\Features\FeatureDefinition;

/**
 * Shared, memoized inputs for one aggregation run.
 */
final class AggregationContext
{
    /** @var array<int, Collection<int, stdClass>> */
    private array $hourGroups = [];

    /** @var array<int, Collection<int, stdClass>> */
    private array $dayGroups = [];

    /** @var array<string, list<string>> */
    private array $featureCache = [];

    /** @var array<int, CarbonImmutable> session start days to (re)compute */
    public array $sessionDays = [];

    public readonly string $pageViewEvent;

    /**
     * @param  array<string, FeatureDefinition>  $features
     */
    public function __construct(
        public readonly Database $db,
        public readonly array $features,
        public readonly AliasResolver $aliases,
    ) {
        $this->pageViewEvent = (string) config('tendril-product.page_view_event', 'page.viewed');
    }

    /**
     * Event counts for one hour grouped by tenant, name and capability context.
     *
     * @return Collection<int, stdClass>
     */
    public function hourGroups(CarbonImmutable $hour): Collection
    {
        return $this->hourGroups[$hour->getTimestamp()] ??= $this->grouped($hour, $hour->addHour(), false);
    }

    /**
     * Event counts for one day grouped additionally by actor and anonymous id.
     *
     * @return Collection<int, stdClass>
     */
    public function dayGroups(CarbonImmutable $day): Collection
    {
        return $this->dayGroups[$day->getTimestamp()] ??= $this->grouped($day, $day->addDay(), true);
    }

    /**
     * @return list<string> feature keys matching the capability tuple
     */
    public function featuresFor(string $name, ?string $route, ?string $component, ?string $action): array
    {
        $key = $name."\0".$route."\0".$component."\0".$action;

        if (! isset($this->featureCache[$key])) {
            $matches = [];

            foreach ($this->features as $feature) {
                if ($feature->matches($name, $route, $component, $action, $this->pageViewEvent)) {
                    $matches[] = $feature->key;
                }
            }

            $this->featureCache[$key] = $matches;
        }

        return $this->featureCache[$key];
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function grouped(CarbonImmutable $from, CarbonImmutable $to, bool $withActors): Collection
    {
        $json = $this->db->json();
        $route = $json->text('context', ['route']);
        $component = $json->text('context', ['component']);
        $action = $json->text('context', ['action']);
        $occurred = $this->db->wrap('occurred_at');

        $columns = ['tenant_type', 'tenant_id', 'name'];

        if ($withActors) {
            $columns = [...$columns, 'actor_type', 'actor_id', 'anonymous_id'];
        }

        $query = $this->db->table(Tables::EVENTS)
            ->select($columns)
            ->selectRaw("{$route} as ctx_route, {$component} as ctx_component, {$action} as ctx_action, count(*) as aggregate")
            ->where('occurred_at', '>=', $this->db->time($from))
            ->where('occurred_at', '<', $this->db->time($to))
            ->groupBy($columns)
            ->groupByRaw("{$route}, {$component}, {$action}");

        if ($withActors) {
            $query->selectRaw("min({$occurred}) as first_at, max({$occurred}) as last_at");
        }

        return $query->get();
    }
}
