<?php

declare(strict_types=1);

use App\Modules\Reservations\DTO\ParseResult;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;

function icalFixture(string $name): string
{
    return file_get_contents(__DIR__ . '/../../Fixtures/ical/' . $name);
}

it('reads the valid Airbnb feed into 4 events without warnings', function () {
    $adapter = new AirbnbAdapter();

    $result = $adapter->parseFeed(icalFixture('airbnb-valid.ics'), 1);

    expect($result)->toBeInstanceOf(ParseResult::class)
        ->and($result->events)->toHaveCount(4)
        ->and($result->warnings)->toBeEmpty();
});