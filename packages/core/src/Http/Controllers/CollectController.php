<?php

namespace VitisStudio\Tendril\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use VitisStudio\Tendril\Core\Acquisition\AcquisitionParser;
use VitisStudio\Tendril\Core\Capture\DedupeKey;
use VitisStudio\Tendril\Core\Capture\Recorder;
use VitisStudio\Tendril\Core\Http\RequestLifecycle;
use VitisStudio\Tendril\Core\Http\RouteNamer;
use VitisStudio\Tendril\Core\Sessions\SessionManager;
use VitisStudio\Tendril\Core\Support\Instant;

/**
 * First-party beacon endpoint. The server remains the source of truth: it
 * validates against the same limits as any event, resolves identity from the
 * session/cookie (never from the payload), applies TrackingDecision through
 * the normal recorder and dedupes on the client-supplied ULID.
 */
final class CollectController
{
    public function __invoke(Request $request): Response
    {
        if (! config('tendril.beacon.enabled', false)) {
            abort(404);
        }

        if (! $this->originAllowed($request)) {
            return response()->noContent(403);
        }

        $raw = (string) $request->getContent();

        if (strlen($raw) > (int) config('tendril.beacon.max_payload_bytes', 16384)) {
            return response()->noContent(413);
        }

        $payload = json_decode($raw, true, 8);

        if (! is_array($payload) || ! is_array($payload['events'] ?? null) || ! array_is_list($payload['events'])) {
            return response()->noContent(400);
        }

        $events = $payload['events'];

        if ($events === [] || count($events) > (int) config('tendril.beacon.max_events', 20)) {
            return response()->noContent(422);
        }

        $allowed = array_values(array_filter((array) config('tendril.beacon.events', []), 'is_string'));
        $valid = [];

        foreach ($events as $event) {
            $normalized = is_array($event) ? $this->validate($event, $allowed) : null;

            if ($normalized === null) {
                return response()->noContent(422);
            }

            $valid[] = $normalized;
        }

        $first = $valid[0];
        $lifecycle = app(RequestLifecycle::class);

        if ($lifecycle->active()) {
            $consent = app(SessionManager::class)->hasConsent($request);
            $acquisition = app(AcquisitionParser::class)->parse($first['utm'], $first['referrer'], $request->getHost(), $consent);
            $landing = $first['name'] === 'page.viewed' ? $first['route'] : null;
            $lifecycle->resolveSession($request, $acquisition, $landing);
        }

        foreach ($valid as $event) {
            app(Recorder::class)->record($event['name'], $event['properties'], [
                'occurred_at' => $event['occurred_at'],
                'source' => 'beacon',
                'dedupe_key' => DedupeKey::beacon($event['id']),
                'context' => array_filter(['route' => $event['route'], 'navigation' => 'beacon']),
            ]);
        }

        return response()->noContent(202);
    }

    /**
     * @param  array<mixed>  $event
     * @param  list<string>  $allowed
     * @return array{id: string, name: string, occurred_at: \Carbon\CarbonImmutable, route: ?string, referrer: ?string, utm: array<string, mixed>, properties: array<string, mixed>}|null
     */
    private function validate(array $event, array $allowed): ?array
    {
        $id = $event['id'] ?? null;
        $name = $event['name'] ?? null;

        if (! is_string($id) || ! Str::isUlid($id) || ! is_string($name) || ! in_array($name, $allowed, true)) {
            return null;
        }

        $path = $event['path'] ?? '/';

        if (! is_string($path) || strlen($path) > 2048 || ! str_starts_with($path, '/')) {
            return null;
        }

        $properties = $event['properties'] ?? [];

        if (! is_array($properties)) {
            return null;
        }

        $now = Instant::now();
        $occurred = $now;
        $drift = (int) config('tendril.beacon.max_clock_drift_seconds', 600);

        if (is_int($event['ts'] ?? null) || is_float($event['ts'] ?? null)) {
            $client = Instant::parse(((float) $event['ts']) / 1000);

            // Client clocks are unreliable: use the client time only within the drift window.
            if (abs($client->getTimestamp() - $now->getTimestamp()) <= $drift) {
                $occurred = $client;
            }
        }

        $utm = is_array($event['utm'] ?? null) ? $event['utm'] : [];
        $referrer = is_string($event['referrer'] ?? null) && strlen($event['referrer']) <= 2048 ? $event['referrer'] : null;

        return [
            'id' => strtoupper($id),
            'name' => $name,
            'occurred_at' => $occurred,
            'route' => RouteNamer::forPath(parse_url($path, PHP_URL_PATH) ?: '/'),
            'referrer' => $referrer,
            'utm' => $utm,
            'properties' => $properties,
        ];
    }

    private function originAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');

        if (! is_string($origin) || $origin === '') {
            return false;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        if (! is_string($host)) {
            return false;
        }

        $allowed = array_map('strtolower', array_filter([
            $request->getHost(),
            parse_url((string) config('app.url'), PHP_URL_HOST),
            ...array_filter((array) config('tendril.beacon.allowed_origins', []), 'is_string'),
        ], 'is_string'));

        return in_array(strtolower($host), $allowed, true);
    }
}
