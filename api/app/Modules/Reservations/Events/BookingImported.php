<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class BookingImported
{
    use Dispatchable;

    public function __construct(
        public readonly int $bookingId,
    ) {
    }
}