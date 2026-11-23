<?php

declare(strict_types=1);

use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function scheduleUrl(int $bookingId): string
{
    return "/api/bookings/{$bookingId}/schedule";
}

function bookingFor(int $propertyId, string $uid = 'fict-0001@cleancheck.test', array $attributes = []): Booking
{
    $source = BookingSource::firstOrCreate(
        ['property_id' => $propertyId, 'channel' => 'airbnb', 'type' => 'url'],
        ['url' => 'https://calendar.example.com/feed.ics?s=fict', 'is_active' => true],
    );

    return Booking::create(array_merge([
        'booking_source_id' => $source->id,
        'external_uid' => $uid,
        'source_check_in_at' => '2026-11-02',
        'source_check_out_at' => '2026-11-05',
        'status' => ReservationStatus::Reserved,
    ], $attributes));
}

beforeEach(function () {
    Event::fake([BookingUpdated::class]);

    $this->property = icalProperty();
    $this->booking = bookingFor($this->property->id);
});

it('lists the bookings of a property with received, adjusted and effective dates', function () {
    $this->booking->update(['overridden_check_out_at' => '2026-11-07']);
    bookingFor(icalProperty()->id, 'fict-other@cleancheck.test');

    authenticateApiUser(userWithRole('supervisor'));
    $this->getJson("/api/properties/{$this->property->id}/bookings")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.propertyId', $this->property->id)
        ->assertJsonPath('data.0.source.checkOut', '2026-11-05')
        ->assertJsonPath('data.0.overridden.checkIn', null)
        ->assertJsonPath('data.0.overridden.checkOut', '2026-11-07')
        ->assertJsonPath('data.0.effective.checkIn', '2026-11-02')
        ->assertJsonPath('data.0.effective.checkOut', '2026-11-07');
});

it('adjusts the check-out and announces the change', function () {
    authenticateApiUser(userWithRole('admin'));
    $this->patchJson(scheduleUrl($this->booking->id), ['check_in' => null, 'check_out' => '2026-11-07'])
        ->assertOk()
        ->assertJsonPath('data.source.checkOut', '2026-11-05')
        ->assertJsonPath('data.effective.checkOut', '2026-11-07');

    expect($this->booking->fresh()->overridden_check_out_at->format('Y-m-d'))->toBe('2026-11-07');

    Event::assertDispatched(BookingUpdated::class, fn (BookingUpdated $event) => $event->bookingId === $this->booking->id);
});

it('clears the adjustment when both dates are null', function () {
    $this->booking->update(['overridden_check_out_at' => '2026-11-07']);

    authenticateApiUser(userWithRole('supervisor'));
    $this->patchJson(scheduleUrl($this->booking->id), ['check_in' => null, 'check_out' => null])
        ->assertOk()
        ->assertJsonPath('data.overridden.checkOut', null)
        ->assertJsonPath('data.effective.checkOut', '2026-11-05');

    Event::assertDispatchedTimes(BookingUpdated::class, 1);
});

it('does not announce a change when the same adjustment is sent again', function () {
    $this->booking->update(['overridden_check_out_at' => '2026-11-07']);

    authenticateApiUser(userWithRole('supervisor'));
    $this->patchJson(scheduleUrl($this->booking->id), ['check_in' => null, 'check_out' => '2026-11-07'])
        ->assertOk();

    Event::assertNotDispatched(BookingUpdated::class);
});

it('rejects an effective check-out that is not after the check-in', function (array $payload) {
    authenticateApiUser(userWithRole('supervisor'));
    $this->patchJson(scheduleUrl($this->booking->id), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('check_out');

    expect($this->booking->fresh()->overridden_check_out_at)->toBeNull();

    Event::assertNotDispatched(BookingUpdated::class);
})->with([
    'check-out before check-in' => [['check_in' => '2026-11-04', 'check_out' => '2026-11-03']],
    'check-out on the received check-in' => [['check_in' => null, 'check_out' => '2026-11-02']],
    'check-in on the received check-out' => [['check_in' => '2026-11-05', 'check_out' => null]],
]);

it('rejects missing fields and invalid date formats', function (array $payload, string $field) {
    authenticateApiUser(userWithRole('supervisor'));
    $this->patchJson(scheduleUrl($this->booking->id), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing check-in' => [['check_out' => '2026-11-07'], 'check_in'],
    'wrong format' => [['check_in' => null, 'check_out' => '07/11/2026'], 'check_out'],
]);

it('rejects adjusting a cancelled booking', function () {
    $this->booking->update(['status' => ReservationStatus::Cancelled]);

    authenticateApiUser(userWithRole('supervisor'));
    $this->patchJson(scheduleUrl($this->booking->id), ['check_in' => null, 'check_out' => '2026-11-07'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('booking');

    Event::assertNotDispatched(BookingUpdated::class);
});

it('returns 404 for a booking that does not exist', function () {
    authenticateApiUser(userWithRole('supervisor'));
    $this->patchJson(scheduleUrl(999), ['check_in' => null, 'check_out' => null])
        ->assertNotFound();
});

it('returns 403 for a service provider without changing anything', function () {
    authenticateApiUser(userWithRole('service_provider'));
    $this->getJson("/api/properties/{$this->property->id}/bookings")->assertForbidden();
    $this->patchJson(scheduleUrl($this->booking->id), ['check_in' => null, 'check_out' => '2026-11-07'])
        ->assertForbidden();

    expect($this->booking->fresh()->overridden_check_out_at)->toBeNull();
});

it('returns 401 without authentication', function () {
    $this->patchJson(scheduleUrl($this->booking->id), ['check_in' => null, 'check_out' => '2026-11-07'])
        ->assertUnauthorized();
});