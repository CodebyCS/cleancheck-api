<?php

namespace App\Modules\Tasks\Support;

/**
 * Guest hours and timezone of a property, as seen by the Tasks module.
 * Times use the "H:i" or "H:i:s" format and are read in the property's timezone.
 */
final readonly class PropertySchedule
{
    public const DEFAULT_TIMEZONE = 'Europe/Lisbon';

    public function __construct(
        public ?string $checkOutTime,
        public ?string $checkInTime,
        public string $timezone = self::DEFAULT_TIMEZONE,
    ) {}
}
