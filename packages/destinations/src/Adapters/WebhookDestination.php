<?php

namespace VitisStudio\Tendril\Destinations\Adapters;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Destinations\Contracts\ConversionDestination;
use VitisStudio\Tendril\Destinations\DeliveryResult;
use VitisStudio\Tendril\Destinations\OutboundConversion;

/**
 * Generic signed webhook (CRM, data pipelines). One POST per batch:
 *   X-Tendril-Signature: t=<unix>,v1=<hex hmac-sha256 of "<t>.<body>">
 * The body carries conversion ids, time and value; no actor identity; click
 * IDs only when include_click_ids is enabled for this destination.
 */
final class WebhookDestination implements ConversionDestination
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly string $name, private readonly array $config)
    {
        if (! is_string($config['url'] ?? null) || $config['url'] === '') {
            throw new InvalidArgumentException("Webhook destination [{$name}] needs a url.");
        }

        if (! is_string($config['secret'] ?? null) || strlen($config['secret']) < 16) {
            throw new InvalidArgumentException("Webhook destination [{$name}] needs a secret of at least 16 characters.");
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function accepts(OutboundConversion $conversion): true|string
    {
        return true;
    }

    public function send(array $conversions): array
    {
        $body = json_encode([
            'destination' => $this->name,
            'conversions' => array_map(fn (OutboundConversion $c) => array_filter([
                'event_id' => $c->eventId(),
                'conversion' => $c->record->conversionKey,
                'event' => $c->destinationEvent,
                'occurred_at' => Instant::serialize($c->record->occurredAt),
                'value' => $c->majorValue(),
                'value_minor' => $c->record->valueMinor,
                'currency' => $c->record->currency,
                'click_ids' => ($this->config['include_click_ids'] ?? false) && $c->clickIds !== [] ? $c->clickIds : null,
            ], fn ($v) => $v !== null), $conversions),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $this->config['secret']);

        try {
            $response = Http::timeout((int) config('tendril-destinations.timeout_seconds', 10))
                ->withHeaders([
                    'X-Tendril-Signature' => "t={$timestamp},v1={$signature}",
                    'User-Agent' => 'Tendril-Destinations/1.0',
                ])
                ->withBody($body, 'application/json')
                ->post((string) $this->config['url']);
        } catch (Throwable $e) {
            return array_map(fn (OutboundConversion $c) => DeliveryResult::failed($c->eventId(), $e->getMessage()), $conversions);
        }

        return array_map(fn (OutboundConversion $c) => $response->successful()
            ? DeliveryResult::delivered($c->eventId(), $response->status())
            : DeliveryResult::failed($c->eventId(), 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500), $response->status()),
            $conversions);
    }

    /**
     * Verify a received signature (for receivers written in PHP/Laravel).
     */
    public static function verify(string $header, string $body, string $secret, int $toleranceSeconds = 300): bool
    {
        if (preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m) !== 1) {
            return false;
        }

        if (abs(time() - (int) $m[1]) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$body, $secret), $m[2]);
    }
}
