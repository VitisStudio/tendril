<?php

namespace VitisStudio\Tendril\Core\Capture;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Support\Instant;

/**
 * Validates and bounds an event envelope before it is buffered.
 *
 * Rules: lower-case dotted names of bounded length, bounded property/context
 * payloads with privacy scrubbing, future-skew rejection and a configurable
 * policy for events far in the past. received_at is always server time.
 */
final class EventNormalizer
{
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_.:\-\/]*$/';

    /** Context keys owned by Tendril; host scope data cannot override them. */
    public const array RESERVED_CONTEXT = ['route', 'component', 'action', 'navigation', 'tags', 'sample_rate', 'clock', 'ip', 'status'];

    public function __construct(private readonly PropertySanitizer $sanitizer) {}

    public static function fromConfig(): self
    {
        return new self(PropertySanitizer::fromConfig());
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function normalize(array $event, ?CarbonImmutable $now = null): NormalizationResult
    {
        $now ??= Instant::now();
        $warnings = [];

        $name = is_string($event['name'] ?? null) ? strtolower(trim($event['name'])) : '';
        $maxName = max(3, (int) config('tendril.limits.name_max_length', 150));

        if ($name === '' || strlen($name) > $maxName || preg_match(self::NAME_PATTERN, $name) !== 1) {
            return NormalizationResult::reject('invalid_name');
        }

        $event['name'] = $name;

        $occurred = Instant::tryParse($event['occurred_at'] ?? null) ?? $now;
        $skew = max(0, (int) config('tendril.limits.future_skew_seconds', 300));

        if ($occurred->greaterThan($now->addSeconds($skew))) {
            return NormalizationResult::reject('future_skew');
        }

        $context = is_array($event['context'] ?? null) ? $event['context'] : [];
        $pastThreshold = max(60, (int) config('tendril.limits.past_threshold_seconds', 7 * 86400));

        if ($occurred->lessThan($now->subSeconds($pastThreshold))) {
            $policy = (string) config('tendril.limits.past_policy', 'flag');

            if ($policy === 'reject') {
                return NormalizationResult::reject('past_skew');
            }

            if ($policy === 'clamp') {
                $occurred = $now;
                $context['clock'] = 'clamped';
            } else {
                $context['clock'] = 'late';
            }

            $warnings[] = 'past_skew';
        }

        $event['occurred_at'] = Instant::serialize($occurred);
        $event['received_at'] = Instant::serialize($now);

        $properties = is_array($event['properties'] ?? null) ? $event['properties'] : [];
        $event['properties'] = $this->sanitizer->sanitize($properties, $warnings);
        $event['context'] = $this->sanitizer->sanitize($context, $warnings, applyAllowList: false);

        $bytes = strlen((string) json_encode($event['properties'])) + strlen((string) json_encode($event['context']));

        if ($bytes > max(512, (int) config('tendril.limits.max_payload_bytes', 16384))) {
            return NormalizationResult::reject('payload_too_large');
        }

        $event['version'] = max(1, min(32767, (int) ($event['version'] ?? 1)));
        $event['source'] = $this->bounded($event['source'] ?? 'app', 32) ?? 'app';
        $event['environment'] = $this->bounded($event['environment'] ?? 'production', 32) ?? 'production';

        foreach (['request_id', 'trace_id', 'anonymous_id', 'actor_id', 'tenant_id'] as $column) {
            $event[$column] = $this->bounded($event[$column] ?? null, 64);
        }

        foreach (['actor_type', 'tenant_type'] as $column) {
            $event[$column] = $this->bounded($event[$column] ?? null, 150);
        }

        foreach (['session_id', 'parent_id'] as $column) {
            $event[$column] = $this->bounded($event[$column] ?? null, 26);
        }

        if ($event['actor_type'] === null || $event['actor_id'] === null) {
            $event['actor_type'] = $event['actor_id'] = null;
        }

        if ($event['tenant_type'] === null || $event['tenant_id'] === null) {
            $event['tenant_type'] = $event['tenant_id'] = null;
        }

        $event['dedupe_key'] = DedupeKey::normalize(is_string($event['dedupe_key'] ?? null) ? $event['dedupe_key'] : (string) $event['id']);

        return NormalizationResult::accept($event, array_values(array_unique($warnings)));
    }

    private function bounded(mixed $value, int $length): ?string
    {
        if (! is_scalar($value) || $value === '') {
            return null;
        }

        $value = (string) $value;

        return strlen($value) > $length ? null : $value;
    }
}
