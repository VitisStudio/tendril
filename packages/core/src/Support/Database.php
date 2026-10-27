<?php

namespace VitisStudio\Tendril\Core\Support;

use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use RuntimeException;

/**
 * Access to the configurable Tendril connection. Resolved lazily on every call
 * so the object holds no per-request state (Octane-safe).
 */
final class Database
{
    public function connectionName(): ?string
    {
        $name = config('tendril.database.connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function connection(): Connection
    {
        $connection = app('db')->connection($this->connectionName());

        if (! $connection instanceof Connection) {
            throw new RuntimeException('Tendril requires an Illuminate\Database\Connection.');
        }

        return $connection;
    }

    public function table(string $table): Builder
    {
        return $this->connection()->table($table);
    }

    public function driver(): string
    {
        return $this->connection()->getDriverName();
    }

    public function json(): JsonSql
    {
        return JsonSql::for($this->connection());
    }

    public function time(DateTimeInterface $value): string
    {
        return Instant::toDatabase($value, $this->driver());
    }

    public function raw(string $sql): Expression
    {
        return new Expression($sql);
    }

    /**
     * Portable string concatenation expression for already-wrapped operands.
     */
    public function concat(string ...$wrapped): string
    {
        return match ($this->driver()) {
            'mysql', 'mariadb' => 'CONCAT('.implode(', ', $wrapped).')',
            default => '('.implode(' || ', $wrapped).')',
        };
    }

    public function wrap(string $column): string
    {
        return $this->connection()->getQueryGrammar()->wrap($column);
    }
}
