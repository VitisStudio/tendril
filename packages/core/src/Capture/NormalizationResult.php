<?php

namespace VitisStudio\Tendril\Core\Capture;

final class NormalizationResult
{
    /**
     * @param  array<string, mixed>|null  $event
     * @param  list<string>  $warnings
     */
    private function __construct(
        public readonly ?array $event,
        public readonly ?string $rejection,
        public readonly array $warnings = [],
    ) {}

    /**
     * @param  array<string, mixed>  $event
     * @param  list<string>  $warnings
     */
    public static function accept(array $event, array $warnings = []): self
    {
        return new self($event, null, $warnings);
    }

    public static function reject(string $reason): self
    {
        return new self(null, $reason);
    }

    public function rejected(): bool
    {
        return $this->rejection !== null;
    }
}
