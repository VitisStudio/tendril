<?php

namespace VitisStudio\Tendril\Core\Identity;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * An (type, id) pair pointing at a host model without holding the model.
 * Only IDs ever enter Context, queue payloads or storage.
 */
abstract class Reference
{
    final public function __construct(
        public readonly string $type,
        public readonly string $id,
    ) {
        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('References need a non-empty type and id.');
        }

        if (strlen($type) > 150 || strlen($id) > 64) {
            throw new InvalidArgumentException('Reference type or id is too long.');
        }
    }

    public static function from(mixed $value): static
    {
        if ($value instanceof static) {
            return $value;
        }

        if ($value instanceof Reference) {
            return new static($value->type, $value->id);
        }

        if ($value instanceof Model) {
            $key = $value->getKey();

            if (! is_scalar($key)) {
                throw new InvalidArgumentException('Model has no scalar key.');
            }

            return new static($value->getMorphClass(), (string) $key);
        }

        if ($value instanceof Authenticatable) {
            $id = $value->getAuthIdentifier();

            if (! is_scalar($id)) {
                throw new InvalidArgumentException('Authenticatable has no scalar identifier.');
            }

            return new static($value::class, (string) $id);
        }

        if (is_array($value) && isset($value['type'], $value['id']) && is_scalar($value['type']) && is_scalar($value['id'])) {
            return new static((string) $value['type'], (string) $value['id']);
        }

        throw new InvalidArgumentException('Unable to build a reference from the given value.');
    }

    public static function tryFrom(mixed $value): ?static
    {
        if ($value === null || $value === false) {
            return null;
        }

        try {
            return static::from($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{type: string, id: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }

    public function key(): string
    {
        return $this->type.'|'.$this->id;
    }

    public function equals(?Reference $other): bool
    {
        return $other !== null && $other->type === $this->type && $other->id === $this->id;
    }
}
