<?php

namespace VitisStudio\Tendril\Core\Support;

/**
 * Builds links from an analytics event to the host's observability tooling
 * (logs, traces) via correlation ids, without copying log payloads into
 * analytics. Templates live in tendril.observability.links, e.g.
 *   'logs' => 'https://logs.example.com/search?q=tendril.request_id:{request_id}'
 * Placeholders: {event_id}, {request_id}, {trace_id}, {session_id}, {occurred_at}.
 */
final class ObservabilityLinks
{
    /**
     * @param  array<string, mixed>|object  $event  a stored row or event array
     * @return array<string, string> name => URL (templates with missing ids are skipped)
     */
    public function for(array|object $event): array
    {
        $event = (array) $event;
        $values = [
            'event_id' => $event['id'] ?? null,
            'request_id' => $event['request_id'] ?? null,
            'trace_id' => $event['trace_id'] ?? null,
            'session_id' => $event['session_id'] ?? null,
            'occurred_at' => isset($event['occurred_at']) ? Instant::serialize(Instant::parse($event['occurred_at'])) : null,
        ];

        $links = [];

        foreach ((array) config('tendril.observability.links', []) as $name => $template) {
            if (! is_string($template) || ! is_string($name)) {
                continue;
            }

            $missing = false;
            $url = preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($values, &$missing): string {
                $value = $values[$m[1]] ?? null;

                if (! is_scalar($value) || $value === '') {
                    $missing = true;

                    return '';
                }

                return rawurlencode((string) $value);
            }, $template);

            if (! $missing && is_string($url)) {
                $links[$name] = $url;
            }
        }

        return $links;
    }
}
