<?php

declare(strict_types=1);

namespace api\app\Modules\Reservations\Ical\Channels;

use App\Modules\Reservations\DTO\ReservationEvent;

interface Channeladapterinterface{
    public function channel(): string;

    public function parseFeed(string $icalRawText, int $propertyId): array;
}
