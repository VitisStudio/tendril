<?php

namespace VitisStudio\Tendril\Marketing\Conversions;

/**
 * Code-defined conversions (Tendril::conversion(...)), populated at boot.
 */
final class ConversionRegistry
{
    /** @var array<string, ConversionDefinition> */
    private array $conversions = [];

    public function put(ConversionDefinition $conversion): void
    {
        $this->conversions[$conversion->key] = $conversion;
    }

    public function get(string $key): ?ConversionDefinition
    {
        return $this->conversions[$key] ?? null;
    }

    /**
     * @return array<string, ConversionDefinition>
     */
    public function all(): array
    {
        return $this->conversions;
    }
}
