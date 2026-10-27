<?php

namespace VitisStudio\Tendril\Core\Exceptions;

use RuntimeException;

final class UnsupportedDriver extends RuntimeException
{
    public static function for(string $driver): self
    {
        return new self("Tendril supports PostgreSQL and MySQL (SQLite for development only); [{$driver}] is not supported.");
    }
}
