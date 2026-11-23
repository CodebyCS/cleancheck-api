<?php
declare(strict_types=1);

namespace App\Modules\Reservations\Enums;

enum ReservationStatus: string
{
    case Reserved = 'reserved';

    case Blocked = 'blocked';

    case Cancelled = 'cancelled';

    case Unknown = 'unknown';
}
