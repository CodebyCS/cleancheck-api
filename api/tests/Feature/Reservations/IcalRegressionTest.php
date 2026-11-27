<?php

declare(strict_types=1);

use App\Models\Task;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * The whole life of one Airbnb calendar, through the same endpoint as the "Sincronizar agora"
 * button and with the real task listeners: creation, repetition, change, failure, cancellation
 * and recovery. If one of these steps breaks, this is the test that tells.
 */
it('keeps bookings and tasks right through the whole life of a calendar', function () {
    // Every booking in the fixtures is still in the future on this date.
    $this->travelTo('2026-10-01 10:00');
    Http::preventStrayRequests();
    fakeDns(['calendar.example.com' => ['52.10.20.30']]);
    Http::fake(['calendar.example.com/*' => Http::sequence()
        ->push(icalFixture('airbnb-valid.ics'))
        ->push(icalFixture('airbnb-valid.ics'))
        ->push(icalFixture('airbnb-changed.ics'))
        ->pushStatus(503)
        ->push('<html>Not a calendar</html>')
        ->push(icalFixture('airbnb-cancelled.ics'))]);

    $source = BookingSource::create([
        'property_id' => icalProperty()->id,
        'channel' => 'airbnb',
        'type' => 'url',
        'url' => 'https://calendar.example.com/feed.ics?s=fict',
        'is_active' => true,
    ]);
    $url = "/api/booking-sources/{$source->id}/sync";
    $booking = fn (string $uid): Booking => Booking::where('external_uid', "{$uid}@cleancheck.test")->sole();
    $activeTasks = fn (): int => Task::where('type', 'checkout_cleaning')->where('status', '!=', 'cancelled')->count();

    authenticateApiUser(userWithRole('supervisor'));

    // 1. Creation: 3 reservations become bookings with a cleaning task; the Airbnb block is skipped.
    $this->postJson($url)->assertOk()->assertJsonCount(3, 'created')->assertJsonCount(1, 'skipped');
    expect(Booking::count())->toBe(3)->and($activeTasks())->toBe(3);

    // 2. Repetition: the same feed changes nothing.
    $this->postJson($url)->assertOk()->assertJsonCount(0, 'created')->assertJsonCount(3, 'existing');
    expect(Booking::count())->toBe(3)->and($activeTasks())->toBe(3);

    // 3. Change: the guest moved fict-0004 one day later; its task follows, no new one.
    $this->postJson($url)->assertOk()->assertJsonCount(1, 'updated')->assertJsonCount(0, 'created');
    expect($booking('fict-0004')->source_check_in_at->toDateString())->toBe('2026-11-03')
        ->and($activeTasks())->toBe(3);

    // 4. Failures: Airbnb down, then a page that is not a calendar. Nothing is lost.
    $this->postJson($url)->assertStatus(422)->assertJsonPath('error.code', 'FETCH_FAILED');
    $this->postJson($url)->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ICAL');
    expect(Booking::where('status', ReservationStatus::Reserved)->count())->toBe(3)
        ->and($activeTasks())->toBe(3);

    // 5. Cancellation and recovery: fict-0002 left the feed, so it and its task are cancelled.
    // The cancelled task leaves the lists (soft delete) but stays in the history.
    $this->postJson($url)->assertOk()->assertJsonCount(1, 'cancelled');
    $cancelledTask = Task::withTrashed()->where('booking_id', $booking('fict-0002')->id)->sole();

    expect($booking('fict-0002')->status)->toBe(ReservationStatus::Cancelled)
        ->and($cancelledTask->status)->toBe('cancelled')
        ->and($cancelledTask->trashed())->toBeTrue()
        ->and($activeTasks())->toBe(2)
        ->and(Booking::count())->toBe(3);

    // Every attempt, good or bad, is in the history with its outcome.
    expect(SyncRun::orderBy('id')->pluck('status')->all())
        ->toBe(['success', 'success', 'success', 'failed', 'failed', 'success']);
});