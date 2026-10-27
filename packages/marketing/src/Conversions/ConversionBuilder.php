<?php

namespace VitisStudio\Tendril\Marketing\Conversions;

use VitisStudio\Tendril\Core\Query\Predicate;

/**
 *   Tendril::conversion('purchase')
 *       ->event('order.completed')
 *       ->value('properties.revenue')->currencyFrom('properties.currency');
 *
 *   Tendril::conversion('consulting-lead')
 *       ->event('lead.created')
 *       ->where([['properties.service', 'eq', 'consulting']]);
 */
final class ConversionBuilder
{
    public function __construct(
        private readonly ConversionRegistry $registry,
        private readonly string $key,
    ) {
        if ($registry->get($key) === null) {
            $registry->put(ConversionDefinition::make($key));
        }
    }

    public function name(string $name): self
    {
        return $this->set('name', $name);
    }

    public function event(string $event): self
    {
        return $this->set('event', strtolower($event));
    }

    /**
     * @param  Predicate|array<string, mixed>|list<array{0: string, 1: string, 2?: mixed}>  $where
     */
    public function where(Predicate|array $where): self
    {
        if (is_array($where)) {
            /** @var array<string, mixed> $tree */
            $tree = $where;
            $where = array_is_list($where) ? Predicate::where($where) : Predicate::fromArray($tree);
        }

        return $this->set('where', $where);
    }

    public function value(string $path, bool $minorUnits = false): self
    {
        $this->set('value_path', $path);

        return $this->set('value_in_minor_units', $minorUnits);
    }

    public function currency(string $currency): self
    {
        return $this->set('currency', strtoupper($currency));
    }

    public function currencyFrom(string $path): self
    {
        return $this->set('currency_path', $path);
    }

    public function definition(): ConversionDefinition
    {
        return $this->registry->get($this->key) ?? ConversionDefinition::make($this->key);
    }

    private function set(string $attribute, mixed $value): self
    {
        $this->registry->put($this->definition()->with($attribute, $value));

        return $this;
    }
}
