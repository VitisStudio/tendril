<?php

namespace VitisStudio\Tendril\Core\Support;

use DateTimeInterface;

/**
 * Small key/value store (tendril_state) for watermarks, cursors and versions.
 */
final class StateStore
{
    public function __construct(private readonly Database $db) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->db->table(Tables::STATE)->where('key', $key)->value('value');

        if (! is_string($value)) {
            return $default;
        }

        $decoded = json_decode($value, true);

        return $decoded ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->db->table(Tables::STATE)->upsert([[
            'key' => $key,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'updated_at' => $this->db->time(Instant::now()),
        ]], ['key'], ['value', 'updated_at']);
    }

    public function forget(string $key): void
    {
        $this->db->table(Tables::STATE)->where('key', $key)->delete();
    }

    public function instant(string $key): ?\Carbon\CarbonImmutable
    {
        return Instant::tryParse($this->get($key));
    }

    public function putInstant(string $key, DateTimeInterface $value): void
    {
        $this->put($key, Instant::serialize($value));
    }

    /**
     * Only move an instant watermark forward.
     */
    public function advance(string $key, DateTimeInterface $value): void
    {
        $current = $this->instant($key);

        if ($current === null || $current->lessThan($value)) {
            $this->putInstant($key, $value);
        }
    }
}
