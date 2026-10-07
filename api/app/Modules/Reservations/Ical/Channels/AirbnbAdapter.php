<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Ical\Channels;

use App\Modules\Reservations\DTO\ParseResult;

final class AirbnbAdapter implements ChannelAdapterInterface
{
    public function channel(): string
    {
        return 'airbnb';
    }

    public function parseFeed(string $icalRawText, int $propertyId): ParseResult
    {
        return new ParseResult();
    }
}