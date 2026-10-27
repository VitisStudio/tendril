<?php

namespace VitisStudio\Tendril\Core\Decisions;

use InvalidArgumentException;

/**
 * The outcome of a TrackingDecision: track, drop, sample, redact or tag.
 */
final class Decision
{
    public const string TRACK = 'track';

    public const string DROP = 'drop';

    public const string SAMPLE = 'sample';

    public const string REDACT = 'redact';

    public const string TAG = 'tag';

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $tags
     */
    private function __construct(
        public readonly string $type,
        public readonly float $rate = 1.0,
        public readonly array $keys = [],
        public readonly array $tags = [],
        public readonly ?string $reason = null,
    ) {}

    public static function track(): self
    {
        return new self(self::TRACK);
    }

    public static function drop(string $reason = 'policy'): self
    {
        return new self(self::DROP, 0.0, reason: $reason);
    }

    /**
     * Keep a deterministic fraction of events (consistent per actor/visitor).
     */
    public static function sample(float $rate): self
    {
        if ($rate < 0.0 || $rate > 1.0) {
            throw new InvalidArgumentException('Sample rate must be between 0 and 1.');
        }

        return new self(self::SAMPLE, $rate);
    }

    /**
     * Remove property paths (dot notation) before storage.
     *
     * @param  list<string>  $keys
     */
    public static function redact(array $keys): self
    {
        return new self(self::REDACT, keys: array_values($keys));
    }

    /**
     * @param  list<string>  $tags
     */
    public static function tag(array $tags): self
    {
        return new self(self::TAG, tags: array_values($tags));
    }
}
