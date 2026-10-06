<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Ical\Channels;

use App\Modules\Reservations\DTO\ReservationEvent;

interface ChannelAdapterInterface{
    public function channel(): string;

    public function parseFeed(string $icalRawText, int $propertyId): array;
}
