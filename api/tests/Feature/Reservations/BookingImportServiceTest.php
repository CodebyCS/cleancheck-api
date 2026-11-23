<?php

declare(strict_types=1);

use App\Modules\Reservations\DTO\ImportResult;
use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Services\BookingImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([BookingImported::class]);

    $this->propertyId = icalProperty()->id;
});

function importIcalFixture(string $fixture, int $propertyId): ImportResult
{
    $adapter = new AirbnbAdapter();
    $result = $adapter->parseFeed(icalFixture($fixture), $propertyId);

    return app(BookingImportService::class)->import($propertyId, $adapter->channel(), $result);
}

it('creates one booking per reserved event', function () {
    $result = importIcalFixture('airbnb-valid.ics', $this->propertyId);

    expect($result->created)->toHaveCount(3)
        ->and($result->existing)->toBeEmpty()
        ->and($result->skipped)->toHaveCount(1);

    $this->assertDatabaseCount('bookings', 3);

    $booking = Booking::where('external_uid', 'fict-0001@cleancheck.test')->first();

    expect($booking->source_check_in_at->format('Y-m-d'))->toBe('2026-10-10')
        ->and($booking->source_check_out_at->format('Y-m-d'))->toBe('2026-10-14');
});

it('dispatches one booking imported event per imported booking', function () {
    importIcalFixture('airbnb-valid.ics', $this->propertyId);

    Event::assertDispatchedTimes(BookingImported::class, 3);

    $booking = Booking::where('external_uid', 'fict-0001@cleancheck.test')->first();

    Event::assertDispatched(
        BookingImported::class,
        fn (BookingImported $event) => $event->bookingId === $booking->id,
    );
});

it('does not duplicate bookings and announces existing ones again on reimport', function () {
    importIcalFixture('airbnb-valid.ics', $this->propertyId);
    $second = importIcalFixture('airbnb-valid.ics', $this->propertyId);

    expect($second->created)->toBeEmpty()
        ->and($second->existing)->toHaveCount(3);

    $this->assertDatabaseCount('bookings', 3);
    $this->assertDatabaseCount('booking_sources', 1);

    Event::assertDispatchedTimes(BookingImported::class, 6);
});

it('keeps unknown events out of the database', function () {
    $result = importIcalFixture('airbnb-invalid.ics', $this->propertyId);

    expect($result->created)->toHaveCount(1)
        ->and($result->ambiguous)->toHaveCount(1)
        ->and($result->warnings)->toHaveCount(6);

    $this->assertDatabaseCount('bookings', 1);

    Event::assertDispatchedTimes(BookingImported::class, 1);
});

it('records a successful sync run', function () {
    importIcalFixture('airbnb-valid.ics', $this->propertyId);

    $source = BookingSource::first();

    $this->assertDatabaseHas('sync_runs', [
        'booking_source_id' => $source->id,
        'status' => 'success',
        'created_count' => 3,
    ]);
});

it('rolls back a failed import, keeps previous data and dispatches no events for it', function () {
    importIcalFixture('airbnb-valid.ics', $this->propertyId);

    Booking::creating(fn () => throw new RuntimeException('Falha simulada.'));

    expect(fn () => importIcalFixture('airbnb-invalid.ics', $this->propertyId))
        ->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('bookings', 3);
    $this->assertDatabaseMissing('bookings', ['external_uid' => 'fict-inv-0005@cleancheck.test']);
    $this->assertDatabaseHas('sync_runs', ['status' => 'failed']);

    Event::assertDispatchedTimes(BookingImported::class, 3);
});