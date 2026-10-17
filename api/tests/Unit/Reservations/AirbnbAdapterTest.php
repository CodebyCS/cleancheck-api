<?php

declare(strict_types=1);

use App\Modules\Reservations\DTO\ParseResult;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Enums\IcalErrorCode;
use App\Modules\Reservations\Exceptions\InvalidIcalException;
use App\Modules\Reservations\Enums\IcalWarningCode;


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

it('extracts the reservation URL from the description', function () {
    $result = (new AirbnbAdapter())->parseFeed(icalFixture('airbnb-valid.ics'), 1);

    expect($result->events[0]->reservationUrl)
        ->toBe('https://www.airbnb.com/hosting/reservations/details/HMFICT0001')
        ->and($result->events[2]->reservationUrl)->toBeNull();
});

it('reads the sequence when present', function () {
    $result = (new AirbnbAdapter())->parseFeed(icalFixture('airbnb-valid.ics'), 1);

    expect($result->events[3]->externalUid)->toBe('fict-0004@cleancheck.test')
        ->and($result->events[3]->sequence)->toBe(1);
});

it('rejects an empty file with EMPTY_FILE', function () {
    expect(fn () => (new AirbnbAdapter())->parseFeed(icalFixture('airbnb-empty.ics'), 1))
        ->toThrow(function (InvalidIcalException $e) {
            expect($e->errorCode)->toBe(IcalErrorCode::EmptyFile);
        });
});

it('rejects a file that is not a VCALENDAR with INVALID_ICAL', function () {
    expect(fn () => (new AirbnbAdapter())->parseFeed(icalFixture('airbnb-not-vcalendar.ics'), 1))
        ->toThrow(function (InvalidIcalException $e) {
            expect($e->errorCode)->toBe(IcalErrorCode::InvalidIcal);
        });
});

it('keeps only the readable events of the invalid feed', function () {
    $result = (new AirbnbAdapter())->parseFeed(icalFixture('airbnb-invalid.ics'), 1);

    $uids = array_map(fn ($event) => $event->externalUid, $result->events);

    expect($uids)->toBe([
        'fict-inv-0005@cleancheck.test',
        'fict-inv-0006@cleancheck.test',
    ]);
});

it('reports one warning per problem, in file order', function () {
    $result = (new AirbnbAdapter())->parseFeed(icalFixture('airbnb-invalid.ics'), 1);

    $codes = array_map(fn ($warning) => $warning->code, $result->warnings);
    $uids = array_map(fn ($warning) => $warning->uid, $result->warnings);

    expect($codes)->toBe([
        IcalWarningCode::MissingUid,
        IcalWarningCode::MissingDates,
        IcalWarningCode::InvalidDateRange,
        IcalWarningCode::UnsupportedDateTime,
        IcalWarningCode::DuplicateUid,
        IcalWarningCode::UnknownSummary,
    ])->and($uids)->toBe([
        null,
        'fict-inv-0002@cleancheck.test',
        'fict-inv-0003@cleancheck.test',
        'fict-inv-0004@cleancheck.test',
        'fict-inv-0005@cleancheck.test',
        'fict-inv-0006@cleancheck.test',
    ]);
});

it('keeps the first event of a duplicated UID', function () {
    $result = (new AirbnbAdapter())->parseFeed(icalFixture('airbnb-invalid.ics'), 1);

    expect($result->events[0]->externalUid)->toBe('fict-inv-0005@cleancheck.test')
        ->and($result->events[0]->checkIn->format('Y-m-d'))->toBe('2026-12-10');
});

it('keeps an event with an unknown summary as unknown', function () {
    $result = (new AirbnbAdapter())->parseFeed(icalFixture('airbnb-invalid.ics'), 1);

    expect($result->events[1]->status)->toBe(ReservationStatus::Unknown)
        ->and($result->events[1]->rawSummary)->toBe('Manutencao');
});