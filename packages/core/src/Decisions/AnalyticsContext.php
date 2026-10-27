<?php

namespace VitisStudio\Tendril\Core\Decisions;

use Illuminate\Http\Request;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Identity\TenantRef;

/**
 * Read-only view of an event about to be recorded, handed to TrackingDecision
 * implementations. The request is available for recognizers (IP ranges, user
 * agents) but none of its raw data is stored.
 */
final class AnalyticsContext
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $name,
        public readonly string $source,
        public readonly string $environment,
        public readonly array $properties,
        public readonly array $context,
        public readonly ?ActorRef $actor,
        public readonly ?TenantRef $tenant,
        public readonly ?string $anonymousId,
        public readonly ?Request $request,
    ) {}

    public function route(): ?string
    {
        $route = $this->context['route'] ?? null;

        return is_string($route) ? $route : null;
    }

    public function path(): ?string
    {
        return $this->request?->path();
    }

    public function userAgent(): ?string
    {
        return $this->request?->userAgent();
    }

    public function ip(): ?string
    {
        return $this->request?->ip();
    }

    /**
     * Stable key used for deterministic sampling.
     */
    public function samplingKey(): string
    {
        return $this->actor?->key() ?? $this->anonymousId ?? $this->name;
    }
}
