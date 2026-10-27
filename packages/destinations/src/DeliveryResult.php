<?php

namespace VitisStudio\Tendril\Destinations;

final class DeliveryResult
{
    public const string DELIVERED = 'delivered';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    public function __construct(
        public readonly string $eventId,
        public readonly string $status,
        public readonly ?string $error = null,
        public readonly ?int $responseCode = null,
    ) {}

    public static function delivered(string $eventId, ?int $code = null): self
    {
        return new self($eventId, self::DELIVERED, null, $code);
    }

    public static function failed(string $eventId, string $error, ?int $code = null): self
    {
        return new self($eventId, self::FAILED, mb_substr($error, 0, 2000), $code);
    }

    public static function skipped(string $eventId, string $reason): self
    {
        return new self($eventId, self::SKIPPED, $reason);
    }
}
