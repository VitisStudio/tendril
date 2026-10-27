<?php

namespace VitisStudio\Tendril\Destinations\Adapters;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;
use VitisStudio\Tendril\Destinations\Contracts\ConversionDestination;
use VitisStudio\Tendril\Destinations\DeliveryResult;
use VitisStudio\Tendril\Destinations\OutboundConversion;

/**
 * Meta Conversions API (server events). Sends event_id for browser/server
 * deduplication and "fbc" built from the captured fbclid. Conversions without
 * an fbclid are skipped: Tendril never sends hashed PII it did not collect.
 */
final class MetaConversionsApiDestination implements ConversionDestination
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly string $name, private readonly array $config)
    {
        foreach (['pixel_id', 'access_token'] as $key) {
            if (! is_string($config[$key] ?? null) || $config[$key] === '') {
                throw new InvalidArgumentException("Meta destination [{$name}] needs {$key}.");
            }
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function accepts(OutboundConversion $conversion): true|string
    {
        return $conversion->clickId('fbclid') !== null ? true : 'missing fbclid (click IDs are opt-in and consent-gated)';
    }

    public function send(array $conversions): array
    {
        $version = (string) ($this->config['api_version'] ?? 'v21.0');
        $url = "https://graph.facebook.com/{$version}/".rawurlencode((string) $this->config['pixel_id']).'/events';

        $payload = [
            'data' => array_map(fn (OutboundConversion $c) => $this->event($c), $conversions),
            'access_token' => (string) $this->config['access_token'],
        ];

        if (is_string($this->config['test_event_code'] ?? null) && $this->config['test_event_code'] !== '') {
            $payload['test_event_code'] = $this->config['test_event_code'];
        }

        try {
            $response = Http::timeout((int) config('tendril-destinations.timeout_seconds', 10))->asJson()->post($url, $payload);
        } catch (Throwable $e) {
            return array_map(fn (OutboundConversion $c) => DeliveryResult::failed($c->eventId(), $e->getMessage()), $conversions);
        }

        $received = $response->json('events_received');

        if ($response->successful() && (int) $received === count($conversions)) {
            return array_map(fn (OutboundConversion $c) => DeliveryResult::delivered($c->eventId(), $response->status()), $conversions);
        }

        $error = $response->json('error.message') ?? ('HTTP '.$response->status());

        return array_map(fn (OutboundConversion $c) => DeliveryResult::failed($c->eventId(), (string) $error, $response->status()), $conversions);
    }

    /**
     * @return array<string, mixed>
     */
    public function event(OutboundConversion $conversion): array
    {
        $event = [
            'event_name' => $conversion->destinationEvent,
            'event_time' => $conversion->record->occurredAt->getTimestamp(),
            'event_id' => $conversion->eventId(),
            'action_source' => 'website',
            'user_data' => [
                'fbc' => 'fb.1.'.($conversion->record->occurredAt->getTimestamp() * 1000).'.'.$conversion->clickId('fbclid'),
            ],
        ];

        if (($value = $conversion->majorValue()) !== null) {
            $event['custom_data'] = ['value' => (float) $value, 'currency' => $conversion->record->currency];
        }

        return $event;
    }
}
