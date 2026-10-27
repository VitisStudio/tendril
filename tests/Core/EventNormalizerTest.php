<?php

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Capture\DedupeKey;
use VitisStudio\Tendril\Core\Capture\EventNormalizer;
use VitisStudio\Tendril\Core\Support\Instant;

function envelope(array $overrides = []): array
{
    return array_merge([
        'id' => '01J00000000000000000000000',
        'name' => 'report.exported',
        'properties' => [],
        'context' => [],
    ], $overrides);
}

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-09-29 12:00:00.123456', 'UTC');
    $this->normalizer = EventNormalizer::fromConfig();
});

it('lower-cases and validates names', function () {
    expect($this->normalizer->normalize(envelope(['name' => 'Report.Exported']), $this->now)->event['name'])->toBe('report.exported');

    foreach (['', ' ', 'has space', '.leading', str_repeat('a', 151), 'emoji.🙂'] as $bad) {
        expect($this->normalizer->normalize(envelope(['name' => $bad]), $this->now)->rejection)->toBe('invalid_name');
    }
});

it('rejects events too far in the future and flags or clamps late ones', function () {
    $future = $this->normalizer->normalize(envelope(['occurred_at' => $this->now->addMinutes(10)]), $this->now);
    expect($future->rejection)->toBe('future_skew');

    $withinSkew = $this->normalizer->normalize(envelope(['occurred_at' => $this->now->addMinutes(2)]), $this->now);
    expect($withinSkew->rejected())->toBeFalse();

    $late = $this->normalizer->normalize(envelope(['occurred_at' => $this->now->subDays(30)]), $this->now);
    expect($late->event['context']['clock'])->toBe('late')
        ->and(Instant::parse($late->event['occurred_at'])->toDateString())->toBe('2026-08-30');

    config(['tendril.limits.past_policy' => 'clamp']);
    $clamped = EventNormalizer::fromConfig()->normalize(envelope(['occurred_at' => $this->now->subDays(30)]), $this->now);
    expect($clamped->event['occurred_at'])->toBe(Instant::serialize($this->now))
        ->and($clamped->event['context']['clock'])->toBe('clamped');

    config(['tendril.limits.past_policy' => 'reject']);
    expect(EventNormalizer::fromConfig()->normalize(envelope(['occurred_at' => $this->now->subDays(30)]), $this->now)->rejection)->toBe('past_skew');
});

it('keeps microsecond precision and always stamps received_at with server time', function () {
    $result = $this->normalizer->normalize(envelope(['occurred_at' => $this->now->subSecond(), 'received_at' => '1999-01-01']), $this->now);

    expect($result->event['occurred_at'])->toBe('2026-09-29T11:59:59.123456Z')
        ->and($result->event['received_at'])->toBe('2026-09-29T12:00:00.123456Z');
});

it('removes blocked keys at every depth and never stores objects', function () {
    $result = $this->normalizer->normalize(envelope(['properties' => [
        'plan' => 'pro',
        'password' => 'hunter2',
        'nested' => ['api_key' => 'k', 'ok' => 1, 'user' => new stdClass],
        'Authorization' => 'Bearer x',
        '_token' => 'csrf',
        'when' => new DateTimeImmutable('2026-01-01T00:00:00Z'),
        'nothing' => null,
    ]]), $this->now);

    expect($result->event['properties'])->toBe([
        'plan' => 'pro',
        'nested' => ['ok' => 1],
        'when' => '2026-01-01T00:00:00.000+00:00',
    ])->and($result->warnings)->toContain('blocked_key', 'unsupported_value');
});

it('bounds depth, property count, string length and payload size', function () {
    config(['tendril.limits.max_depth' => 2, 'tendril.limits.max_properties' => 3, 'tendril.limits.max_string_length' => 16]);
    $normalizer = EventNormalizer::fromConfig();

    $result = $normalizer->normalize(envelope(['properties' => [
        'a' => str_repeat('x', 100),
        'b' => ['c' => ['d' => 1]],
        'e' => 1, 'f' => 2, 'g' => 3,
    ]]), $this->now);

    expect($result->event['properties']['a'])->toHaveLength(16)
        ->and($result->event['properties'])->not->toHaveKey('b')
        ->and(count($result->event['properties']))->toBe(3)
        ->and($result->warnings)->toContain('max_depth', 'max_properties');

    config(['tendril.limits.max_payload_bytes' => 600, 'tendril.limits.max_string_length' => 1024, 'tendril.limits.max_properties' => 64]);
    $big = EventNormalizer::fromConfig()->normalize(envelope(['properties' => ['blob' => str_repeat('y', 1000)]]), $this->now);
    expect($big->rejection)->toBe('payload_too_large');
});

it('applies the top-level allow list when configured', function () {
    config(['tendril.privacy.allowed_keys' => ['plan']]);

    $result = EventNormalizer::fromConfig()->normalize(envelope(['properties' => ['plan' => 'pro', 'other' => 'x']]), $this->now);

    expect($result->event['properties'])->toBe(['plan' => 'pro']);
});

it('drops half-specified actor and tenant references', function () {
    $result = $this->normalizer->normalize(envelope(['actor_type' => 'App\\Models\\User', 'actor_id' => null, 'tenant_type' => null, 'tenant_id' => '4']), $this->now);

    expect($result->event['actor_type'])->toBeNull()->and($result->event['tenant_id'])->toBeNull();
});

it('builds deterministic, bounded dedupe keys', function () {
    expect(DedupeKey::livewire('req1', 'reports.show', 'export', 2))->toBe('lw:req1:reports.show:export:2')
        ->and(DedupeKey::pageView('req1'))->toBe('pv:req1');

    $long = DedupeKey::normalize(str_repeat('k', 400));
    expect(strlen($long))->toBeLessThanOrEqual(DedupeKey::MAX_LENGTH)
        ->and($long)->toBe(DedupeKey::normalize(str_repeat('k', 400)));
});
