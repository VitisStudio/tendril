<?php

namespace VitisStudio\Tendril\Timescale;

/**
 * What the connected TimescaleDB actually offers. Never assume compression,
 * continuous aggregates or tiering exist: Apache-licensed builds (e.g. Neon)
 * ship hypertables and drop_chunks only.
 */
final class Capabilities
{
    public function __construct(
        public readonly bool $postgres,
        public readonly bool $installed,
        public readonly ?string $version,
        public readonly ?string $license,
        public readonly bool $hypertable,
        public readonly bool $compression,
        public readonly bool $continuousAggregates,
        public readonly bool $tiering,
        public readonly bool $eventsIsHypertable,
    ) {}

    public static function unavailable(bool $postgres = false): self
    {
        return new self($postgres, false, null, null, false, false, false, false, false);
    }

    /**
     * @param  array{version: ?string, license: ?string, functions: list<string>, events_hypertable: bool}  $probe
     */
    public static function fromProbe(array $probe): self
    {
        $license = $probe['license'] !== null ? strtolower($probe['license']) : null;
        $functions = array_map('strtolower', $probe['functions']);
        // The community ("timescale") license enables TSL features; "apache" does not.
        $tsl = $license === 'timescale';

        return new self(
            postgres: true,
            installed: $probe['version'] !== null,
            version: $probe['version'],
            license: $license,
            hypertable: $probe['version'] !== null && in_array('create_hypertable', $functions, true),
            compression: $tsl && in_array('add_compression_policy', $functions, true),
            continuousAggregates: $tsl,
            tiering: in_array('add_tiering_policy', $functions, true),
            eventsIsHypertable: $probe['events_hypertable'],
        );
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function toArray(): array
    {
        return [
            'postgres' => $this->postgres,
            'timescale_installed' => $this->installed,
            'timescale_version' => $this->version,
            'license' => $this->license,
            'hypertable' => $this->hypertable,
            'compression' => $this->compression,
            'continuous_aggregates' => $this->continuousAggregates,
            'tiering' => $this->tiering,
            'events_is_hypertable' => $this->eventsIsHypertable,
        ];
    }
}
