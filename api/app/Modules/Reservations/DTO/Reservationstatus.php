<?php
declare(strict_types=1);

namespace api\app\Modules\Reservations\DTO;

enum Reservationstatus: string
{
    case Reserved = 'reserved';

    case Blocked = 'blocked';

    case Cancelled = 'cancelled';

    case Unknown = 'unknown';
}
