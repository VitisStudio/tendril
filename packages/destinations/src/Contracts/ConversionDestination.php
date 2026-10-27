<?php

namespace VitisStudio\Tendril\Destinations\Contracts;

use VitisStudio\Tendril\Destinations\DeliveryResult;
use VitisStudio\Tendril\Destinations\OutboundConversion;

/**
 * Consumes normalized conversions; never reshapes core events.
 */
interface ConversionDestination
{
    public function name(): string;

    /**
     * Whether this conversion can be sent (e.g. Google Ads needs a gclid).
     * Returning a string skips it with that reason.
     */
    public function accepts(OutboundConversion $conversion): true|string;

    /**
     * @param  list<OutboundConversion>  $conversions
     * @return list<DeliveryResult> one result per conversion
     */
    public function send(array $conversions): array;
}
