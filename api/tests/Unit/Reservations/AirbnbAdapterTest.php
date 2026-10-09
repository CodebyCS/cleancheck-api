<?php

declare(strict_types=1);

use App\Modules\Reservations\DTO\ParseResult;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Enums\ReservationStatus;

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

it('maps the fields of a simple reservation', function () {
    $result = (new AirbnbAdapter())->parseFeed(icalFixture('airbnb-valid.ics'), 42);

    $event = $result->events[0];

    expect($event->externalUid)->toBe('fict-0001@cleancheck.test')
        ->and($event->propertyId)->toBe(42)
        ->and($event->channel)->toBe('airbnb')
        ->and($event->checkIn->format('Y-m-d'))->toBe('2026-10-10')
        ->and($event->checkOut->format('Y-m-d'))->toBe('2026-10-14')
        ->and($event->nights())->toBe(4)
        ->and($event->status)->toBe(ReservationStatus::Reserved)
        ->and($event->sequence)->toBeNull()
        ->and($event->rawSummary)->toBe('Reserved');
});

it('classifies "Airbnb (Not available)" as blocked', function () {
    $result = (new AirbnbAdapter())->parseFeed(icalFixture('airbnb-valid.ics'), 1);

    $event = $result->events[2];

    expect($event->externalUid)->toBe('fict-0003@cleancheck.test')
        ->and($event->status)->toBe(ReservationStatus::Blocked)
        ->and($event->nights())->toBe(7);
});