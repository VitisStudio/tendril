<?php

namespace VitisStudio\Tendril\Core\Decisions;

final class Outcome
{
    /**
     * @param  list<string>  $redact
     * @param  list<string>  $tags
     */
    private function __construct(
        public readonly bool $keep,
        public readonly ?string $reason,
        public readonly float $sampleRate,
        public readonly array $redact,
        public readonly array $tags,
    ) {}

    public static function dropped(string $reason): self
    {
        return new self(false, $reason, 0.0, [], []);
    }

    /**
     * @param  list<string>  $redact
     * @param  list<string>  $tags
     */
    public static function kept(float $rate, array $redact, array $tags): self
    {
        return new self(true, null, $rate, $redact, $tags);
    }

    /**
     * Apply redactions/tags/sample rate to a normalized event.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function apply(array $event): array
    {
        $properties = is_array($event['properties'] ?? null) ? $event['properties'] : [];

        foreach ($this->redact as $path) {
            \Illuminate\Support\Arr::forget($properties, $path);
        }

        $event['properties'] = $properties;
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];

        if ($this->tags !== []) {
            $existing = is_array($context['tags'] ?? null) ? $context['tags'] : [];
            $context['tags'] = array_values(array_unique([...$existing, ...$this->tags]));
        }

        if ($this->sampleRate < 1.0) {
            $context['sample_rate'] = round($this->sampleRate, 6);
        }

        $event['context'] = $context;

        return $event;
    }
}
