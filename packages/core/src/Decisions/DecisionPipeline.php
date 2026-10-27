<?php

namespace VitisStudio\Tendril\Core\Decisions;

use InvalidArgumentException;
use Throwable;
use VitisStudio\Tendril\Core\Contracts\TrackingDecision;
use VitisStudio\Tendril\Core\Support\FailOpen;

/**
 * Composes TrackingDecision implementations: drop short-circuits, sample rates
 * multiply, redactions and tags accumulate.
 */
final class DecisionPipeline
{
    /** @var list<TrackingDecision>|null */
    private ?array $deciders = null;

    /** @var list<TrackingDecision> registered at boot via Tendril::decideUsing() */
    private array $extra = [];

    /** @var list<TrackingDecision>|null configured deciders, built once */
    private ?array $configured = null;

    /**
     * @param  list<TrackingDecision>|null  $deciders
     */
    public function __construct(?array $deciders = null)
    {
        $this->deciders = $deciders;
    }

    public function push(TrackingDecision $decision): void
    {
        $this->extra[] = $decision;
    }

    /**
     * Forget cached configured deciders (config changed at runtime).
     */
    public function refresh(): void
    {
        $this->configured = null;
    }

    public function evaluate(AnalyticsContext $context): Outcome
    {
        $rate = 1.0;
        $redact = [];
        $tags = [];

        foreach ($this->deciders() as $decider) {
            try {
                $decision = $decider->decide($context);
            } catch (Throwable $e) {
                // Dropping is the privacy-safe default: the broken hook may
                // have intended to drop or redact this event.
                FailOpen::report($e, 'decision');

                return Outcome::dropped('decision_failed');
            }

            switch ($decision->type) {
                case Decision::DROP:
                    return Outcome::dropped($decision->reason ?? 'policy');
                case Decision::SAMPLE:
                    $rate *= $decision->rate;
                    break;
                case Decision::REDACT:
                    $redact = [...$redact, ...$decision->keys];
                    break;
                case Decision::TAG:
                    $tags = [...$tags, ...$decision->tags];
                    break;
            }
        }

        if ($rate < 1.0 && ! self::inSample($context->samplingKey(), $rate)) {
            return Outcome::dropped('sampled');
        }

        return Outcome::kept($rate, array_values(array_unique($redact)), array_values(array_unique($tags)));
    }

    /**
     * Deterministic sampling: the same key is always in or out for a given rate.
     */
    public static function inSample(string $key, float $rate): bool
    {
        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        $bucket = hexdec(substr(hash('sha256', $key), 0, 8)) / 0xFFFFFFFF;

        return $bucket < $rate;
    }

    /**
     * @return list<TrackingDecision>
     */
    private function deciders(): array
    {
        if ($this->deciders !== null) {
            return [...$this->deciders, ...$this->extra];
        }

        if ($this->configured === null) {
            $this->configured = [];

            foreach ((array) config('tendril.decisions', []) as $entry) {
                $this->configured[] = self::build($entry);
            }
        }

        return [...$this->configured, ...$this->extra];
    }

    public static function build(mixed $entry): TrackingDecision
    {
        if ($entry instanceof TrackingDecision) {
            return $entry;
        }

        $arguments = [];

        if (is_array($entry)) {
            $arguments = is_array($entry[1] ?? null) ? $entry[1] : [];
            $entry = $entry[0] ?? null;
        }

        if (! is_string($entry) || ! class_exists($entry)) {
            throw new InvalidArgumentException('Tracking decisions must be class names or [class, arguments].');
        }

        $instance = app()->make($entry, $arguments);

        if (! $instance instanceof TrackingDecision) {
            throw new InvalidArgumentException("[{$entry}] must implement TrackingDecision.");
        }

        return $instance;
    }
}
