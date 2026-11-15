<?php

declare(strict_types=1);

use App\Models\Task;
use App\Modules\Properties\Models\Property;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Tasks\Actions\CreateCheckoutCleaningTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-11-01 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function listenerBooking(): Booking
{
    $property = Property::create([
        'name' => 'Casa dos Eventos',
        'address' => 'Rua Fictícia 12',
        'postal_code' => '1200-012',
        'city' => 'Lisboa',
    ]);

    $source = BookingSource::create([
        'property_id' => $property->id,
        'channel' => 'test',
        'type' => 'url',
        'is_active' => true,
    ]);

    return Booking::create([
        'booking_source_id' => $source->id,
        'external_uid' => 'listener',
        'source_check_in_at' => '2026-11-16 15:00:00',
        'source_check_out_at' => '2026-11-20 11:00:00',
        'status' => 'reserved',
    ]);
}

it('reschedules the task when a booking is updated', function () {
    $booking = listenerBooking();
    $task = app(CreateCheckoutCleaningTask::class)->execute($booking->id);

    $booking->update(['overridden_check_out_at' => '2026-11-22 11:00:00']);
    BookingUpdated::dispatch($booking->id);

    expect($task->fresh()->scheduled_start_at->format('Y-m-d H:i'))->toBe('2026-11-22 11:30');
});

it('cancels and soft deletes the pending task when a booking is cancelled', function () {
    $booking = listenerBooking();
    $task = app(CreateCheckoutCleaningTask::class)->execute($booking->id);

    $booking->update(['status' => 'cancelled']);
    BookingCancelled::dispatch($booking->id);

    $task = Task::withTrashed()->findOrFail($task->id);

    expect($task->status)->toBe('cancelled')
        ->and($task->deleted_at)->not->toBeNull();
});

it('does not cancel a task that is already in progress when the booking is cancelled', function () {
    $booking = listenerBooking();
    $task = app(CreateCheckoutCleaningTask::class)->execute($booking->id);
    $task->update(['status' => 'in_progress', 'started_at' => now()]);

    $booking->update(['status' => 'cancelled']);
    BookingCancelled::dispatch($booking->id);

    $task = Task::withTrashed()->findOrFail($task->id);

    expect($task->status)->toBe('in_progress')
        ->and($task->deleted_at)->toBeNull();
});

it('handles the same cancellation event twice without errors', function () {
    $booking = listenerBooking();
    $task = app(CreateCheckoutCleaningTask::class)->execute($booking->id);

    $booking->update(['status' => 'cancelled']);
    BookingCancelled::dispatch($booking->id);
    BookingCancelled::dispatch($booking->id);

    expect(Task::withTrashed()->where('booking_id', $booking->id)->count())->toBe(1)
        ->and(Task::withTrashed()->findOrFail($task->id)->status)->toBe('cancelled');
});