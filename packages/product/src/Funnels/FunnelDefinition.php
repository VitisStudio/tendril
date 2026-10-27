<?php

namespace VitisStudio\Tendril\Product\Funnels;

use InvalidArgumentException;
use JsonSerializable;
use VitisStudio\Tendril\Core\Capture\EventNormalizer;
use VitisStudio\Tendril\Core\Query\Predicate;

/**
 * Ordered predicates with a conversion window, measured from the first step.
 */
final class FunnelDefinition implements JsonSerializable
{
    /**
     * @param  list<array{event: string, where: Predicate, label: string}>  $steps
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly array $steps,
        public readonly int $windowSeconds,
    ) {
        if (count($steps) < 2 || count($steps) > 10) {
            throw new InvalidArgumentException('Funnels need between 2 and 10 steps.');
        }

        if ($windowSeconds < 1 || $windowSeconds > 366 * 86400) {
            throw new InvalidArgumentException('Funnel windows must be between 1 second and 366 days.');
        }
    }

    /**
     * @param  array<string, mixed>  $data  {key, name, definition: {steps: [...], window_seconds}}
     */
    public static function fromArray(array $data): self
    {
        $definition = is_array($data['definition'] ?? null) ? $data['definition'] : $data;
        $steps = [];

        foreach ((array) ($definition['steps'] ?? []) as $i => $step) {
            if (! is_array($step) || ! is_string($step['event'] ?? null)) {
                throw new InvalidArgumentException("Funnel step {$i} needs an event.");
            }

            $event = strtolower(trim($step['event']));

            if (preg_match(EventNormalizer::NAME_PATTERN, $event) !== 1) {
                throw new InvalidArgumentException("Invalid event name [{$event}] in step {$i}.");
            }

            $steps[] = [
                'event' => $event,
                'where' => Predicate::fromArray(is_array($step['where'] ?? null) ? $step['where'] : null),
                'label' => is_string($step['label'] ?? null) && $step['label'] !== '' ? $step['label'] : $event,
            ];
        }

        return new self(
            (string) ($data['key'] ?? 'adhoc'),
            (string) ($data['name'] ?? ($data['key'] ?? 'Funnel')),
            $steps,
            (int) ($definition['window_seconds'] ?? 86400),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'steps' => array_map(fn (array $s) => [
                'event' => $s['event'],
                'where' => $s['where']->isEmpty() ? null : $s['where']->toArray(),
                'label' => $s['label'],
            ], $this->steps),
            'window_seconds' => $this->windowSeconds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'definition' => $this->definition()];
    }
}
