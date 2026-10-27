<?php

namespace VitisStudio\Tendril\Destinations\Adapters;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use VitisStudio\Tendril\Destinations\Contracts\ConversionDestination;
use VitisStudio\Tendril\Destinations\DeliveryResult;
use VitisStudio\Tendril\Destinations\OutboundConversion;

/**
 * Google Ads offline click conversions (uploadClickConversions) with OAuth
 * refresh-token authentication and partial-failure handling. Requires a
 * captured gclid; orderId is the Tendril event id for deduplication.
 */
final class GoogleAdsDestination implements ConversionDestination
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly string $name, private readonly array $config)
    {
        foreach (['customer_id', 'developer_token', 'client_id', 'client_secret', 'refresh_token'] as $key) {
            if (! is_string($config[$key] ?? null) || $config[$key] === '') {
                throw new InvalidArgumentException("Google Ads destination [{$name}] needs {$key}.");
            }
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function accepts(OutboundConversion $conversion): true|string
    {
        if ($conversion->clickId('gclid') === null) {
            return 'missing gclid (click IDs are opt-in and consent-gated)';
        }

        return str_starts_with($conversion->destinationEvent, 'customers/') ? true : 'conversion mapping must be a conversionActions resource name';
    }

    public function send(array $conversions): array
    {
        try {
            $token = $this->accessToken();
        } catch (Throwable $e) {
            return array_map(fn (OutboundConversion $c) => DeliveryResult::failed($c->eventId(), 'oauth: '.$e->getMessage()), $conversions);
        }

        $customer = preg_replace('/\D/', '', (string) $this->config['customer_id']);
        $version = (string) ($this->config['api_version'] ?? 'v21');
        $headers = ['developer-token' => (string) $this->config['developer_token']];

        if (is_string($this->config['login_customer_id'] ?? null) && $this->config['login_customer_id'] !== '') {
            $headers['login-customer-id'] = preg_replace('/\D/', '', $this->config['login_customer_id']);
        }

        try {
            $response = Http::timeout((int) config('tendril-destinations.timeout_seconds', 10))
                ->withToken($token)
                ->withHeaders($headers)
                ->asJson()
                ->post("https://googleads.googleapis.com/{$version}/customers/{$customer}:uploadClickConversions", [
                    'conversions' => array_map(fn (OutboundConversion $c) => $this->conversion($c), $conversions),
                    'partialFailure' => true,
                ]);
        } catch (Throwable $e) {
            return array_map(fn (OutboundConversion $c) => DeliveryResult::failed($c->eventId(), $e->getMessage()), $conversions);
        }

        if (! $response->successful()) {
            $error = $response->json('error.message') ?? ('HTTP '.$response->status());

            return array_map(fn (OutboundConversion $c) => DeliveryResult::failed($c->eventId(), (string) $error, $response->status()), $conversions);
        }

        $failedIndexes = $this->failedIndexes($response->json('partialFailureError'));

        $results = [];

        foreach (array_values($conversions) as $i => $conversion) {
            $results[] = isset($failedIndexes[$i])
                ? DeliveryResult::failed($conversion->eventId(), $failedIndexes[$i], $response->status())
                : DeliveryResult::delivered($conversion->eventId(), $response->status());
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function conversion(OutboundConversion $conversion): array
    {
        $payload = [
            'gclid' => $conversion->clickId('gclid'),
            'conversionAction' => $conversion->destinationEvent,
            'conversionDateTime' => $conversion->record->occurredAt->utc()->format('Y-m-d H:i:sP'),
            'orderId' => $conversion->eventId(),
        ];

        if (($value = $conversion->majorValue()) !== null) {
            $payload['conversionValue'] = (float) $value;
            $payload['currencyCode'] = $conversion->record->currency;
        }

        return $payload;
    }

    private function accessToken(): string
    {
        $cacheKey = 'tendril:google-ads:token:'.sha1((string) $this->config['client_id'].'|'.(string) $this->config['refresh_token']);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token',
            'client_id' => (string) $this->config['client_id'],
            'client_secret' => (string) $this->config['client_secret'],
            'refresh_token' => (string) $this->config['refresh_token'],
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('token refresh failed: HTTP '.$response->status());
        }

        Cache::put($cacheKey, $token, max(60, ((int) ($response->json('expires_in') ?? 3600)) - 60));

        return $token;
    }

    /**
     * Map partial-failure errors to conversion indexes (fieldPathElements "conversions"[index]).
     *
     * @return array<int, string>
     */
    private function failedIndexes(mixed $partialFailure): array
    {
        if (! is_array($partialFailure)) {
            return [];
        }

        $out = [];
        $message = is_string($partialFailure['message'] ?? null) ? $partialFailure['message'] : 'partial failure';

        foreach ((array) ($partialFailure['details'] ?? []) as $detail) {
            foreach ((array) (is_array($detail) ? ($detail['errors'] ?? []) : []) as $error) {
                if (! is_array($error)) {
                    continue;
                }

                foreach ((array) ($error['location']['fieldPathElements'] ?? []) as $element) {
                    if (is_array($element) && ($element['fieldName'] ?? null) === 'conversions' && isset($element['index'])) {
                        $out[(int) $element['index']] = is_string($error['message'] ?? null) ? $error['message'] : $message;
                    }
                }
            }
        }

        return $out;
    }
}
