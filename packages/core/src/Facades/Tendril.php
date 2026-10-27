<?php

namespace VitisStudio\Tendril\Core\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null track(string $name, array<string, mixed> $properties = [], ?\DateTimeInterface $occurredAt = null, mixed $actor = null, mixed $tenant = null, ?string $dedupeKey = null, array<string, scalar> $context = [], ?string $source = null, int $version = 1)
 * @method static mixed withContext(array<string, scalar> $context, callable $callback)
 * @method static mixed withParent(string $eventId, callable $callback)
 * @method static void identify(mixed $actor)
 * @method static void alias(string $anonymousId, mixed $actor)
 * @method static void setTenant(mixed $tenant)
 * @method static mixed forTenant(mixed $tenant, callable $callback)
 * @method static void flush()
 * @method static string erase(mixed $actor, ?string $performedBy = null)
 * @method static string eraseAnonymous(string $anonymousId, ?string $performedBy = null)
 * @method static \VitisStudio\Tendril\Core\Presence\PresenceManager presence()
 * @method static string|null requestId()
 * @method static string|null sessionId()
 * @method static string|null anonymousId()
 * @method static bool enabled()
 * @method static array<string, string> observabilityLinks(array<string, mixed>|object $event)
 * @method static void consentUsing(\Closure $callback)
 * @method static void resolveActorUsing(\Closure $callback)
 * @method static void resolveTenantUsing(\Closure $callback)
 * @method static void decideUsing(\VitisStudio\Tendril\Core\Contracts\TrackingDecision $decision)
 * @method static \VitisStudio\Tendril\Core\Testing\RecordingTransport fake()
 * @method static \VitisStudio\Tendril\Product\Features\FeatureBuilder feature(string $key)
 * @method static \VitisStudio\Tendril\Marketing\Conversions\ConversionBuilder conversion(string $key)
 *
 * @see \VitisStudio\Tendril\Core\Tendril
 */
class Tendril extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \VitisStudio\Tendril\Core\Tendril::class;
    }
}
