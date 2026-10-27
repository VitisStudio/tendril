<?php

namespace VitisStudio\Tendril\Core\Testing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Support\Instant;

/**
 * Deterministic synthetic analytics history for correctness, aging and load
 * tests (and the workbench demo): many tenants, skewed "hot" features,
 * anonymous visitors with campaigns, anonymous-to-known transitions via
 * alias links, conversions with revenue, and bursty Livewire traffic.
 * Writes through the real BatchWriter, never through capture.
 */
final class SyntheticDataset
{
    public const array ROUTES = ['home', 'pricing', 'reports.index', 'reports.show', 'settings.index', 'billing.index'];

    public const array CAMPAIGNS = [
        ['google', 'cpc', 'brand'],
        ['newsletter', 'email', 'september'],
        ['linkedin', 'social', 'launch'],
        [null, null, null],
    ];

    private Randomizer $random;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    /** @var list<array<string, mixed>> */
    private array $sessions = [];

    /** @var list<array<string, mixed>> */
    private array $touchpoints = [];

    /** @var list<array<string, mixed>> */
    private array $aliases = [];

    private int $written = 0;

    /**
     * @param  array{tenants?: int, actors_per_tenant?: int, visitors_per_day?: int, days?: int, start?: CarbonImmutable, activity?: float, burst?: int, actor_type?: string, tenant_type?: string, actor_id_offset?: int, flush_every?: int}  $options
     */
    public function __construct(private readonly array $options = [], int $seed = 42)
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    /**
     * @return array{events: int, days: int, actors: int, tenants: int}
     */
    public function generate(): array
    {
        $tenants = (int) ($this->options['tenants'] ?? 3);
        $actorsPerTenant = (int) ($this->options['actors_per_tenant'] ?? 6);
        $visitorsPerDay = (int) ($this->options['visitors_per_day'] ?? 3);
        $days = (int) ($this->options['days'] ?? 14);
        $start = Instant::day($this->options['start'] ?? Instant::now()->subDays($days - 1));
        $activity = (float) ($this->options['activity'] ?? 0.5);
        $burst = (int) ($this->options['burst'] ?? 3);
        $actorType = (string) ($this->options['actor_type'] ?? 'App\\Models\\User');
        $tenantType = (string) ($this->options['tenant_type'] ?? 'App\\Models\\Team');
        $offset = (int) ($this->options['actor_id_offset'] ?? 1000);

        // Each actor gets a first day and a churn probability (retention curves).
        $actors = [];

        for ($t = 1; $t <= $tenants; $t++) {
            for ($a = 1; $a <= $actorsPerTenant; $a++) {
                $actors[] = [
                    'type' => $actorType,
                    'id' => (string) ($offset + $t * 100 + $a),
                    'tenant' => (string) $t,
                    'first' => $this->random->getInt(0, max(0, $days - 1)),
                    'churn' => $this->random->getInt(5, 60) / 100,
                    'anon' => (string) Str::ulid(),
                ];
            }
        }

        for ($d = 0; $d < $days; $d++) {
            $day = $start->addDays($d);

            foreach ($actors as $actor) {
                if ($d < $actor['first'] || $this->random->getFloat(0, 1) > $activity * (1 - $actor['churn'] * min(1, ($d - $actor['first']) / max(1, $days)))) {
                    continue;
                }

                $this->actorDay($actor, $day, $tenantType, $d === $actor['first'], $burst);
            }

            for ($v = 0; $v < $visitorsPerDay; $v++) {
                $this->visitor($day, $actors, $tenantType, $actorType);
            }

            if (count($this->events) >= (int) ($this->options['flush_every'] ?? 2000)) {
                $this->flush();
            }
        }

        $this->flush();

        return ['events' => $this->written, 'days' => $days, 'actors' => count($actors), 'tenants' => $tenants];
    }

    /**
     * @param  array{type: string, id: string, tenant: string, first: int, churn: float, anon: string}  $actor
     */
    private function actorDay(array $actor, CarbonImmutable $day, string $tenantType, bool $firstDay, int $burst): void
    {
        $at = $day->addSeconds($this->random->getInt(6 * 3600, 20 * 3600))->addMicroseconds($this->random->getInt(0, 999999));
        $session = (string) Str::ulid($at);
        $base = ['actor_type' => $actor['type'], 'actor_id' => $actor['id'], 'tenant_type' => $tenantType, 'tenant_id' => $actor['tenant'], 'session_id' => $session, 'anonymous_id' => $actor['anon']];

        $this->session($session, $at, $base, null);

        if ($firstDay) {
            $this->event('signup.completed', $at, $base, [], []);
        }

        $this->event('auth.login', $at = $at->addSeconds(2), $base, ['guard' => 'web'], []);

        $views = $this->random->getInt(1, 5);

        for ($i = 0; $i < $views; $i++) {
            // Skew: reports are the hot feature.
            $route = $this->random->getInt(1, 100) <= 55 ? 'reports.show' : self::ROUTES[$this->random->getInt(0, count(self::ROUTES) - 1)];
            $at = $at->addSeconds($this->random->getInt(5, 300));
            $this->event('page.viewed', $at, $base, ['status' => 200], ['route' => $route, 'navigation' => 'full']);

            if ($route === 'reports.show') {
                // Bursty Livewire traffic.
                for ($b = 0, $n = $this->random->getInt(0, $burst); $b < $n; $b++) {
                    $at = $at->addMilliseconds($this->random->getInt(200, 4000));
                    $this->event('livewire.action', $at, $base, [], ['component' => 'reports.show', 'action' => $this->random->getInt(0, 3) === 0 ? 'export' : 'filter']);
                }

                if ($this->random->getInt(1, 100) <= 30) {
                    $this->event('report.exported', $at = $at->addSeconds(1), $base, ['format' => $this->random->getInt(0, 1) === 0 ? 'xlsx' : 'csv'], ['route' => 'reports.show']);
                }
            }
        }

        if ($this->random->getInt(1, 100) <= 8) {
            $this->event('order.completed', $at->addSeconds(30), $base, [
                'revenue' => number_format($this->random->getInt(1000, 50000) / 100, 2, '.', ''),
                'currency' => $this->random->getInt(0, 3) === 0 ? 'EUR' : 'USD',
            ], []);
        }
    }

    /**
     * Anonymous visitor with acquisition; some sign up (explicit alias link).
     *
     * @param  list<array{type: string, id: string, tenant: string, first: int, churn: float, anon: string}>  $actors
     */
    private function visitor(CarbonImmutable $day, array $actors, string $tenantType, string $actorType): void
    {
        $at = $day->addSeconds($this->random->getInt(0, 86399));
        $anon = (string) Str::ulid($at);
        $session = (string) Str::ulid($at);
        [$source, $medium, $campaign] = self::CAMPAIGNS[$this->random->getInt(0, count(self::CAMPAIGNS) - 1)];
        $base = ['anonymous_id' => $anon, 'session_id' => $session];

        $this->session($session, $at, $base, [$source, $medium, $campaign]);
        $this->event('page.viewed', $at, $base, ['status' => 200], ['route' => 'home', 'navigation' => 'full']);
        $this->event('page.viewed', $at = $at->addSeconds(40), $base, ['status' => 200], ['route' => 'pricing', 'navigation' => 'full']);

        if ($this->random->getInt(1, 100) <= 25) {
            $this->event('lead.created', $at = $at->addSeconds(60), $base, ['service' => $this->random->getInt(0, 1) === 0 ? 'consulting' : 'design'], []);
        }

        if ($actors !== [] && $this->random->getInt(1, 100) <= 20) {
            // Anonymous -> known: link this visitor to an existing actor.
            $actor = $actors[$this->random->getInt(0, count($actors) - 1)];
            $at = $at->addSeconds(90);
            $this->aliases[] = [
                'action' => 'link',
                'anonymous_id' => $anon,
                'actor_type' => $actor['type'],
                'actor_id' => $actor['id'],
                'at' => Instant::serialize($at),
                'since' => Instant::serialize($day),
                'reason' => 'registered',
            ];
            $this->event('order.completed', $at->addSeconds(5), ['actor_type' => $actorType, 'actor_id' => $actor['id'], 'tenant_type' => $tenantType, 'tenant_id' => $actor['tenant']] + $base, [
                'revenue' => '49.00',
                'currency' => 'USD',
            ], []);
        }
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array{0: ?string, 1: ?string, 2: ?string}|null  $campaign
     */
    private function session(string $id, CarbonImmutable $at, array $base, ?array $campaign): void
    {
        $this->sessions[] = [
            'id' => $id,
            'open' => [
                'anonymous_id' => $base['anonymous_id'] ?? null,
                'actor_type' => $base['actor_type'] ?? null,
                'actor_id' => $base['actor_id'] ?? null,
                'tenant_type' => $base['tenant_type'] ?? null,
                'tenant_id' => $base['tenant_id'] ?? null,
                'started_at' => Instant::serialize($at),
                'landing_route' => 'home',
                'utm_source' => $campaign[0] ?? null,
                'utm_medium' => $campaign[1] ?? null,
                'utm_campaign' => $campaign[2] ?? null,
                'environment' => 'testing',
            ],
            'last_seen_at' => Instant::serialize($at->addMinutes(5)),
        ];

        if ($campaign !== null && $campaign[0] !== null) {
            $this->touchpoints[] = [
                'session_id' => $id,
                'occurred_at' => Instant::serialize($at),
                'anonymous_id' => $base['anonymous_id'] ?? null,
                'actor_type' => $base['actor_type'] ?? null,
                'actor_id' => $base['actor_id'] ?? null,
                'tenant_type' => $base['tenant_type'] ?? null,
                'tenant_id' => $base['tenant_id'] ?? null,
                'source' => $campaign[0],
                'medium' => $campaign[1],
                'campaign' => $campaign[2],
                'landing_route' => 'home',
                'environment' => 'testing',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context
     */
    private function event(string $name, CarbonImmutable $at, array $base, array $properties, array $context): void
    {
        $id = (string) Str::ulid($at);

        $this->events[] = [
            'id' => $id,
            'occurred_at' => Instant::serialize($at),
            'received_at' => Instant::serialize($at),
            'name' => $name,
            'version' => 1,
            'actor_type' => $base['actor_type'] ?? null,
            'actor_id' => $base['actor_id'] ?? null,
            'anonymous_id' => $base['anonymous_id'] ?? null,
            'session_id' => $base['session_id'] ?? null,
            'tenant_type' => $base['tenant_type'] ?? null,
            'tenant_id' => $base['tenant_id'] ?? null,
            'request_id' => $id,
            'trace_id' => null,
            'parent_id' => null,
            'dedupe_key' => $id,
            'source' => str_starts_with($name, 'livewire') ? 'livewire' : 'http',
            'environment' => 'testing',
            'properties' => $properties,
            'context' => $context,
        ];
    }

    private function flush(): void
    {
        if ($this->events === [] && $this->sessions === []) {
            return;
        }

        $result = app(BatchWriter::class)->write(Batch::make($this->events, $this->sessions, $this->touchpoints, $this->aliases));
        $this->written += $result->inserted;
        $this->events = $this->sessions = $this->touchpoints = $this->aliases = [];
    }
}
