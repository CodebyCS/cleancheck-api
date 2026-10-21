<?php

declare(strict_types=1);

use App\Modules\Reservations\DTO\ImportResult;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Services\BookingImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function importIcalFixture(string $fixture, int $propertyId = 1): ImportResult
{
    $adapter = new AirbnbAdapter();
    $result = $adapter->parseFeed(icalFixture($fixture), $propertyId);

    return (new BookingImportService())->import($propertyId, $adapter->channel(), $result);
}

it('creates one booking per reserved event', function () {
    $result = importIcalFixture('airbnb-valid.ics');

    expect($result->created)->toHaveCount(3)
        ->and($result->existing)->toBeEmpty()
        ->and($result->skipped)->toHaveCount(1);

    $this->assertDatabaseCount('bookings', 3);

    $booking = Booking::where('external_uid', 'fict-0001@cleancheck.test')->first();

    expect($booking->source_check_in_at->format('Y-m-d'))->toBe('2026-10-10')
        ->and($booking->source_check_out_at->format('Y-m-d'))->toBe('2026-10-14');
});

it('does not duplicate bookings when the same file is imported twice', function () {
    importIcalFixture('airbnb-valid.ics');
    $second = importIcalFixture('airbnb-valid.ics');

    expect($second->created)->toBeEmpty()
        ->and($second->existing)->toHaveCount(3);

    $this->assertDatabaseCount('bookings', 3);
    $this->assertDatabaseCount('booking_sources', 1);
});

it('keeps unknown events out of the database', function () {
    $result = importIcalFixture('airbnb-invalid.ics');

    expect($result->created)->toHaveCount(1)
        ->and($result->ambiguous)->toHaveCount(1)
        ->and($result->warnings)->toHaveCount(6);

    $this->assertDatabaseCount('bookings', 1);
});

it('records a successful sync run', function () {
    importIcalFixture('airbnb-valid.ics');

    $source = BookingSource::first();

    $this->assertDatabaseHas('sync_runs', [
        'booking_source_id' => $source->id,
        'status' => 'success',
        'created_count' => 3,
    ]);
});