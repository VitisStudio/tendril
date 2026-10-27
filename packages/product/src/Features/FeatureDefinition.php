<?php

namespace VitisStudio\Tendril\Product\Features;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A feature is a definition over facts: event name patterns, page-view route
 * patterns and Livewire component[@action] patterns. Changing a definition
 * re-evaluates history (derived data is rebuilt).
 */
final class FeatureDefinition implements JsonSerializable
{
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9_.\-]{0,99}$/';

    /**
     * @param  list<string>  $events
     * @param  list<string>  $routes
     * @param  list<string>  $components
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly array $events = [],
        public readonly array $routes = [],
        public readonly array $components = [],
        public readonly string $source = 'data',
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid feature key [{$key}].");
        }
    }

    public static function make(string $key, string $source = 'code'): self
    {
        return new self($key, Str::headline($key), source: $source);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $source = 'data'): self
    {
        $definition = is_array($data['definition'] ?? null) ? $data['definition'] : $data;

        return new self(
            (string) ($data['key'] ?? ''),
            (string) ($data['name'] ?? Str::headline((string) ($data['key'] ?? ''))),
            isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
            self::patterns($definition['events'] ?? []),
            self::patterns($definition['routes'] ?? []),
            self::patterns($definition['components'] ?? []),
            $source,
        );
    }

    /**
     * @param  list<string>  $events
     */
    public function withEvents(array $events): self
    {
        return new self($this->key, $this->name, $this->description, self::patterns($events), $this->routes, $this->components, $this->source);
    }

    /**
     * @param  list<string>  $routes
     */
    public function withRoutes(array $routes): self
    {
        return new self($this->key, $this->name, $this->description, $this->events, self::patterns($routes), $this->components, $this->source);
    }

    /**
     * @param  list<string>  $components
     */
    public function withComponents(array $components): self
    {
        return new self($this->key, $this->name, $this->description, $this->events, $this->routes, self::patterns($components), $this->source);
    }

    public function withName(string $name): self
    {
        return new self($this->key, $name, $this->description, $this->events, $this->routes, $this->components, $this->source);
    }

    public function withDescription(?string $description): self
    {
        return new self($this->key, $this->name, $description, $this->events, $this->routes, $this->components, $this->source);
    }

    public function isEmpty(): bool
    {
        return $this->events === [] && $this->routes === [] && $this->components === [];
    }

    /**
     * Routes only match page views; events and components match any event.
     */
    public function matches(string $name, ?string $route, ?string $component, ?string $action, string $pageViewEvent = 'page.viewed'): bool
    {
        if ($this->events !== [] && Str::is($this->events, $name)) {
            return true;
        }

        if ($route !== null && $this->routes !== [] && $name === $pageViewEvent && Str::is($this->routes, $route)) {
            return true;
        }

        if ($component !== null) {
            foreach ($this->components as $pattern) {
                [$componentPattern, $actionPattern] = array_pad(explode('@', $pattern, 2), 2, null);

                if (Str::is((string) $componentPattern, $component)
                    && ($actionPattern === null || ($action !== null && Str::is($actionPattern, $action)))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{events: list<string>, routes: list<string>, components: list<string>}
     */
    public function definition(): array
    {
        return ['events' => $this->events, 'routes' => $this->routes, 'components' => $this->components];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'definition' => $this->definition(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function hash(): string
    {
        return sha1((string) json_encode([$this->key, $this->definition()]));
    }

    /**
     * @return list<string>
     */
    private static function patterns(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $pattern) {
            if (is_string($pattern) && ($pattern = trim($pattern)) !== '' && strlen($pattern) <= 191) {
                $out[] = $pattern;
            }
        }

        return array_values(array_unique($out));
    }
}
