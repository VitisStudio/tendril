<?php

namespace VitisStudio\Tendril\Core\Exceptions;

use InvalidArgumentException;

final class InvalidPredicate extends InvalidArgumentException
{
    public static function because(string $reason, string $at = '$'): self
    {
        return new self("Invalid predicate at {$at}: {$reason}");
    }
}
