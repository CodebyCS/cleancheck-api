<?php

declare(strict_types=1);

use App\Models\Task;
use App\Modules\Properties\Models\Property;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Tasks\Actions\CreateCheckoutCleaningTask;
use App\Modules\Tasks\Actions\UpdateTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-11-01 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function rescheduleProperty(): Property
{
    return Property::create([
        'name' => 'Casa do Reagendamento',
        'address' => 'Rua Fictícia 11',
        'postal_code' => '1200-011',
        'city' => 'Lisboa',
    ]);
}

function rescheduleBooking(Property $property, string $uid, string $checkIn, string $checkOut): Booking
{
    $source = BookingSource::firstOrCreate(
        ['property_id' => $property->id, 'channel' => 'test', 'type' => 'url'],
        ['is_active' => true],
    );

    return Booking::create([
        'booking_source_id' => $source->id,
        'external_uid' => $uid,
        'source_check_in_at' => $checkIn,
        'source_check_out_at' => $checkOut,
        'status' => 'reserved',
    ]);
}

function rescheduleTask(Booking $booking, array $attributes = []): Task
{
    return Task::create(array_merge([
        'booking_id' => $booking->id,
        'property_id' => $booking->bookingSource->property_id,
        'type' => 'checkout_cleaning',
        'status' => 'pending',
        'scheduled_start_at' => '2026-11-20 11:30:00',
        'scheduled_end_at' => null,
    ], $attributes));
}

it('moves a pending checkout cleaning when the check-out changes', function () {
    $booking = rescheduleBooking(rescheduleProperty(), 'moved', '2026-11-16 15:00:00', '2026-11-20 11:00:00');
    $task = app(CreateCheckoutCleaningTask::class)->execute($booking->id);

    expect($task->scheduled_start_at->format('Y-m-d H:i'))->toBe('2026-11-20 11:30');

    $booking->update(['overridden_check_out_at' => '2026-11-22 11:00:00']);
    app(UpdateTask::class)->forBooking($booking->id);

    expect($task->fresh()->scheduled_start_at->format('Y-m-d H:i'))->toBe('2026-11-22 11:30')
        ->and($task->fresh()->status)->toBe('pending');
});

it('keeps a valid window when the check-out and the next check-in are on the same day', function () {
    $property = rescheduleProperty();
    $booking = rescheduleBooking($property, 'current', '2026-11-16 15:00:00', '2026-11-20 11:00:00');
    rescheduleBooking($property, 'next', '2026-11-20 15:00:00', '2026-11-22 11:00:00');

    $task = app(CreateCheckoutCleaningTask::class)->execute($booking->id);
    app(UpdateTask::class)->forBooking($booking->id);

    $task = $task->fresh();

    expect($task->scheduled_start_at->format('Y-m-d H:i'))->toBe('2026-11-20 11:30')
        ->and($task->scheduled_end_at->format('Y-m-d H:i'))->toBe('2026-11-20 14:30')
        ->and($task->scheduled_end_at->greaterThan($task->scheduled_start_at))->toBeTrue();
});

it('gives the same result when the same change is applied twice', function () {
    $booking = rescheduleBooking(rescheduleProperty(), 'repeated', '2026-11-16 15:00:00', '2026-11-20 11:00:00');
    $task = app(CreateCheckoutCleaningTask::class)->execute($booking->id);

    $booking->update(['overridden_check_out_at' => '2026-11-23 11:00:00']);

    app(UpdateTask::class)->forBooking($booking->id);
    $afterFirst = $task->fresh()->scheduled_start_at->format('Y-m-d H:i');

    app(UpdateTask::class)->forBooking($booking->id);

    expect($task->fresh()->scheduled_start_at->format('Y-m-d H:i'))->toBe($afterFirst)->toBe('2026-11-23 11:30')
        ->and(Task::where('booking_id', $booking->id)->count())->toBe(1);
});

it('does not change tasks that are already in progress or completed', function (string $status) {
    Log::spy();

    $booking = rescheduleBooking(rescheduleProperty(), 'protected', '2026-11-16 15:00:00', '2026-11-20 11:00:00');
    $task = rescheduleTask($booking, ['status' => $status]);

    $booking->update(['overridden_check_out_at' => '2026-11-22 11:00:00']);
    app(UpdateTask::class)->forBooking($booking->id);

    $task = $task->fresh();

    expect($task->scheduled_start_at->format('Y-m-d H:i'))->toBe('2026-11-20 11:30')
        ->and($task->status)->toBe($status);

    // The change is not applied silently: it is logged.
    Log::shouldHaveReceived('warning')->once();
})->with(['in_progress', 'completed']);

it('moves only the deadline of an inspection when the check-in changes', function () {
    $booking = rescheduleBooking(rescheduleProperty(), 'inspection', '2026-11-16 15:00:00', '2026-11-20 11:00:00');
    $task = rescheduleTask($booking, [
        'type' => 'checkin_inspection',
        'scheduled_start_at' => '2026-11-10 09:00:00',
        'scheduled_end_at' => '2026-11-16 14:30:00',
    ]);

    $booking->update(['overridden_check_in_at' => '2026-11-17 15:00:00']);
    app(UpdateTask::class)->forBooking($booking->id);

    $task = $task->fresh();

    expect($task->scheduled_start_at->format('Y-m-d H:i'))->toBe('2026-11-10 09:00')
        ->and($task->scheduled_end_at->format('Y-m-d H:i'))->toBe('2026-11-17 14:30');
});

it('leaves the task untouched when the new dates are invalid', function () {
    $booking = rescheduleBooking(rescheduleProperty(), 'invalid', '2026-11-16 15:00:00', '2026-11-20 11:00:00');
    $task = app(CreateCheckoutCleaningTask::class)->execute($booking->id);

    // A check-out before the check-in is not a valid booking.
    $booking->update(['overridden_check_out_at' => '2026-11-10 11:00:00']);

    app(UpdateTask::class)->forBooking($booking->id);

    expect($task->fresh()->scheduled_start_at->format('Y-m-d H:i'))->toBe('2026-11-20 11:30');
});

it('ignores bookings that do not exist or are no longer reserved', function () {
    $booking = rescheduleBooking(rescheduleProperty(), 'gone', '2026-11-16 15:00:00', '2026-11-20 11:00:00');
    $task = app(CreateCheckoutCleaningTask::class)->execute($booking->id);

    $booking->update(['status' => 'cancelled', 'overridden_check_out_at' => '2026-11-22 11:00:00']);

    app(UpdateTask::class)->forBooking($booking->id);
    app(UpdateTask::class)->forBooking(999999);

    expect($task->fresh()->scheduled_start_at->format('Y-m-d H:i'))->toBe('2026-11-20 11:30');
});