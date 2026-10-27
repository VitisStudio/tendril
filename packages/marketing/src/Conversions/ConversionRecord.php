<?php

namespace VitisStudio\Tendril\Marketing\Conversions;

use Carbon\CarbonImmutable;
use JsonSerializable;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Support\Instant;

/**
 * A normalized conversion: one matching fact, with its identity resolved
 * through alias ranges and its value in minor units. Destinations consume
 * these; they never reshape core events.
 */
final class ConversionRecord implements JsonSerializable
{
    public function __construct(
        public readonly string $conversionKey,
        public readonly string $eventId,
        public readonly CarbonImmutable $occurredAt,
        public readonly ?ActorRef $actor,
        public readonly ?string $anonymousId,
        public readonly ?string $sessionId,
        public readonly ?string $tenantType,
        public readonly ?string $tenantId,
        public readonly ?int $valueMinor,
        public readonly ?string $currency,
    ) {}

    /**
     * Subject for uniques/attribution: the (resolved) actor, else the visitor.
     */
    public function subject(): ?ActorRef
    {
        return $this->actor ?? ($this->anonymousId !== null ? ActorRef::anonymous($this->anonymousId) : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'conversion' => $this->conversionKey,
            'event_id' => $this->eventId,
            'occurred_at' => Instant::serialize($this->occurredAt),
            'actor' => $this->actor?->toArray(),
            'anonymous_id' => $this->anonymousId,
            'session_id' => $this->sessionId,
            'value_minor' => $this->valueMinor,
            'currency' => $this->currency,
        ];
    }
}
