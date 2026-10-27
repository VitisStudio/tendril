<?php

namespace VitisStudio\Tendril\Core\Diagnostics;

final class CheckResult
{
    public const string OK = 'ok';

    public const string WARN = 'warn';

    public const string FAIL = 'fail';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $message,
        public readonly array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(string $name, string $message, array $data = []): self
    {
        return new self($name, self::OK, $message, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function warn(string $name, string $message, array $data = []): self
    {
        return new self($name, self::WARN, $message, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fail(string $name, string $message, array $data = []): self
    {
        return new self($name, self::FAIL, $message, $data);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}
