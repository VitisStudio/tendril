<?php

namespace VitisStudio\Tendril\Core\Support;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use VitisStudio\Tendril\Core\Exceptions\UnsupportedDriver;

/**
 * Driver-specific JSON expressions. Paths are validated against a strict key
 * grammar before being embedded; values are always bound as parameters.
 *
 * PostgreSQL columns are jsonb; MySQL uses native JSON; SQLite (dev only)
 * uses the JSON1 functions.
 */
final class JsonSql
{
    public const string KEY_PATTERN = '/^[A-Za-z0-9_\-]{1,64}$/';

    private function __construct(
        private readonly string $driver,
        private readonly Connection $connection,
    ) {
        if (! in_array($driver, ['pgsql', 'mysql', 'sqlite'], true)) {
            throw UnsupportedDriver::for($driver);
        }
    }

    public static function for(Connection $connection): self
    {
        return new self($connection->getDriverName(), $connection);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * @param  list<string>  $path
     */
    public static function assertPath(array $path): void
    {
        if ($path === [] || count($path) > 5) {
            throw new InvalidArgumentException('JSON paths must have between 1 and 5 segments.');
        }

        foreach ($path as $segment) {
            if (! is_string($segment) || preg_match(self::KEY_PATTERN, $segment) !== 1) {
                throw new InvalidArgumentException("Invalid JSON path segment [{$segment}].");
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function splitPath(string $dotted): array
    {
        $path = explode('.', $dotted);
        self::assertPath($path);

        return $path;
    }

    /**
     * Text value of a scalar at the path (used for grouping and string comparison).
     *
     * @param  list<string>  $path
     */
    public function text(string $column, array $path): string
    {
        self::assertPath($path);
        $col = $this->wrap($column);

        return match ($this->driver) {
            'pgsql' => "({$col} #>> '".$this->pgPath($path)."')",
            'mysql' => "json_unquote(json_extract({$col}, '".$this->jsonPath($path)."'))",
            'sqlite' => "json_extract({$col}, '".$this->jsonPath($path)."')",
            default => throw UnsupportedDriver::for($this->driver),
        };
    }

    /**
     * Boolean SQL asserting the JSON type at the path. Types: string, number, boolean.
     *
     * @param  list<string>  $path
     */
    public function isType(string $column, array $path, string $type): string
    {
        self::assertPath($path);
        $col = $this->wrap($column);

        return match ($this->driver) {
            'pgsql' => "jsonb_typeof({$col} #> '".$this->pgPath($path)."') ".match ($type) {
                'string' => "= 'string'",
                'number' => "= 'number'",
                'boolean' => "= 'boolean'",
                default => throw new InvalidArgumentException("Unknown JSON type [{$type}]."),
            },
            'mysql' => "json_type(json_extract({$col}, '".$this->jsonPath($path)."')) ".match ($type) {
                'string' => "= 'STRING'",
                'number' => "IN ('INTEGER', 'DOUBLE', 'DECIMAL', 'UNSIGNED INTEGER')",
                'boolean' => "= 'BOOLEAN'",
                default => throw new InvalidArgumentException("Unknown JSON type [{$type}]."),
            },
            'sqlite' => "json_type({$col}, '".$this->jsonPath($path)."') ".match ($type) {
                'string' => "= 'text'",
                'number' => "IN ('integer', 'real')",
                'boolean' => "IN ('true', 'false')",
                default => throw new InvalidArgumentException("Unknown JSON type [{$type}]."),
            },
            default => throw UnsupportedDriver::for($this->driver),
        };
    }

    /**
     * Numeric value at the path, NULL when the value is not a JSON number.
     *
     * @param  list<string>  $path
     */
    public function number(string $column, array $path): string
    {
        self::assertPath($path);
        $col = $this->wrap($column);
        $guard = $this->isType($column, $path, 'number');

        return match ($this->driver) {
            'pgsql' => "(CASE WHEN {$guard} THEN ({$col} #>> '".$this->pgPath($path)."')::numeric END)",
            'mysql' => "(CASE WHEN {$guard} THEN CAST(json_unquote(json_extract({$col}, '".$this->jsonPath($path)."')) AS DECIMAL(38,10)) END)",
            'sqlite' => "(CASE WHEN {$guard} THEN json_extract({$col}, '".$this->jsonPath($path)."') END)",
            default => throw UnsupportedDriver::for($this->driver),
        };
    }

    /**
     * Placeholder for a bound number. PDO binds PHP floats as strings, and
     * SQLite orders every INTEGER/REAL below every TEXT, so an uncast
     * `120 <= '80.5'` is true there; PostgreSQL and MySQL coerce the literal.
     */
    public function numberParam(): string
    {
        return match ($this->driver) {
            'pgsql' => 'CAST(? AS numeric)',
            'mysql' => 'CAST(? AS DECIMAL(38,10))',
            'sqlite' => 'CAST(? AS REAL)',
            default => throw UnsupportedDriver::for($this->driver),
        };
    }

    /**
     * SQL comparing a JSON boolean at the path with a bound 'true'/'false' string.
     *
     * @param  list<string>  $path
     */
    public function booleanEquals(string $column, array $path): string
    {
        self::assertPath($path);
        $col = $this->wrap($column);

        return match ($this->driver) {
            'pgsql' => "({$col} #> '".$this->pgPath($path)."') = CAST(? AS jsonb)",
            'mysql' => "json_extract({$col}, '".$this->jsonPath($path)."') = CAST(? AS JSON)",
            'sqlite' => "json_type({$col}, '".$this->jsonPath($path)."') = ?",
            default => throw UnsupportedDriver::for($this->driver),
        };
    }

    /**
     * @param  list<string>  $path
     */
    public function exists(string $column, array $path): string
    {
        self::assertPath($path);
        $col = $this->wrap($column);

        return match ($this->driver) {
            'pgsql' => "({$col} #> '".$this->pgPath($path)."') IS NOT NULL",
            'mysql' => "json_extract({$col}, '".$this->jsonPath($path)."') IS NOT NULL",
            'sqlite' => "json_type({$col}, '".$this->jsonPath($path)."') IS NOT NULL",
            default => throw UnsupportedDriver::for($this->driver),
        };
    }

    /**
     * Expression that returns the column with the given paths removed.
     *
     * @param  list<list<string>>  $paths
     */
    public function remove(string $column, array $paths): string
    {
        $col = $this->wrap($column);

        if ($paths === []) {
            return $col;
        }

        foreach ($paths as $path) {
            self::assertPath($path);
        }

        if ($this->driver === 'pgsql') {
            $expr = $col;
            foreach ($paths as $path) {
                $expr = "({$expr} #- '".$this->pgPath($path)."')";
            }

            return $expr;
        }

        $args = implode(', ', array_map(fn (array $path): string => "'".$this->jsonPath($path)."'", $paths));

        return $this->driver === 'mysql'
            ? "json_remove({$col}, {$args})"
            : "json_remove({$col}, {$args})";
    }

    /**
     * @param  list<string>  $path
     */
    private function pgPath(array $path): string
    {
        return '{'.implode(',', $path).'}';
    }

    /**
     * @param  list<string>  $path
     */
    private function jsonPath(array $path): string
    {
        return '$'.implode('', array_map(static fn (string $key): string => '."'.$key.'"', $path));
    }

    private function wrap(string $column): string
    {
        return $this->connection->getQueryGrammar()->wrap($column);
    }
}
