<?php

namespace App\Modules\Tasks\Support;

use Illuminate\Support\Carbon;

final readonly class BookingSnapshot
{
    public function __construct(
        public int $bookingId,
        public ?int $propertyId,
        public ?Carbon $checkIn,
        public ?Carbon $checkOut,
        public ?Carbon $nextCheckIn,
        public bool $propertyActive = false,
    ) {}
}