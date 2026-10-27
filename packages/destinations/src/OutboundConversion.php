<?php

namespace VitisStudio\Tendril\Destinations;

use VitisStudio\Tendril\Marketing\Conversions\ConversionRecord;
use VitisStudio\Tendril\Marketing\Money\Money;

/**
 * A normalized conversion prepared for one destination: the record, the
 * destination-side event/action name, and click IDs captured (opt-in,
 * consent-gated) on the converting session.
 */
final class OutboundConversion
{
    /**
     * @param  array<string, string>  $clickIds
     */
    public function __construct(
        public readonly ConversionRecord $record,
        public readonly string $destinationEvent,
        public readonly array $clickIds = [],
    ) {}

    public function eventId(): string
    {
        return $this->record->eventId;
    }

    /**
     * Value in major units as a decimal string (e.g. "12.34"), or null.
     */
    public function majorValue(): ?string
    {
        if ($this->record->valueMinor === null || $this->record->currency === null) {
            return null;
        }

        $exponent = Money::exponent($this->record->currency);
        $minor = $this->record->valueMinor;
        $sign = $minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);

        return $exponent === 0
            ? $sign.$digits
            : $sign.substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }

    public function clickId(string $param): ?string
    {
        return $this->clickIds[$param] ?? null;
    }
}
