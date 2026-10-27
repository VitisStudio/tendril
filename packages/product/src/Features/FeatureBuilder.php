<?php

namespace VitisStudio\Tendril\Product\Features;

/**
 * Fluent code definition:
 *
 *   Tendril::feature('reporting')
 *       ->events(['report.viewed', 'report.exported'])
 *       ->routes(['reports.*'])
 *       ->components(['reports.show@export']);
 */
final class FeatureBuilder
{
    public function __construct(
        private readonly FeatureRegistry $registry,
        private readonly string $key,
    ) {
        if ($registry->get($key) === null) {
            $registry->put(FeatureDefinition::make($key));
        }
    }

    public function name(string $name): self
    {
        return $this->update(fn (FeatureDefinition $f) => $f->withName($name));
    }

    public function description(?string $description): self
    {
        return $this->update(fn (FeatureDefinition $f) => $f->withDescription($description));
    }

    /**
     * @param  list<string>  $events
     */
    public function events(array $events): self
    {
        return $this->update(fn (FeatureDefinition $f) => $f->withEvents($events));
    }

    /**
     * @param  list<string>  $routes
     */
    public function routes(array $routes): self
    {
        return $this->update(fn (FeatureDefinition $f) => $f->withRoutes($routes));
    }

    /**
     * @param  list<string>  $components
     */
    public function components(array $components): self
    {
        return $this->update(fn (FeatureDefinition $f) => $f->withComponents($components));
    }

    public function definition(): FeatureDefinition
    {
        return $this->registry->get($this->key) ?? FeatureDefinition::make($this->key);
    }

    /**
     * @param  callable(FeatureDefinition): FeatureDefinition  $change
     */
    private function update(callable $change): self
    {
        $this->registry->put($change($this->definition()));

        return $this;
    }
}
