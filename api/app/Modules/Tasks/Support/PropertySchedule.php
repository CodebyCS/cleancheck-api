<?php

namespace App\Modules\Tasks\Support;

/**
 * Reference hours of a property, as configured in the Properties module.
 * Times use the "H:i" or "H:i:s" format and are read in the property's timezone.
 */
final readonly class PropertySchedule
{
    public function __construct(
        public ?string $checkOutTime,
        public ?string $checkInTime,
        public string $timezone,
    ) {}
}
