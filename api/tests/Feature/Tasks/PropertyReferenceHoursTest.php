<?php

declare(strict_types=1);

use App\Models\Task;
use App\Modules\Properties\Models\Property;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Tasks\Actions\CreateCheckoutCleaningTask;
use App\Modules\Tasks\Contracts\BookingDataProvider;
use App\Modules\Tasks\Support\BookingSnapshot;
use App\Modules\Tasks\Support\PropertySchedule;
use App\Modules\Tasks\Validators\TaskGenerationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function referenceHoursProperty(array $overrides = []): Property
{
    return Property::create(array_merge([
        'name' => 'Casa das Horas',
        'address' => 'Rua Fictícia 7',
        'postal_code' => '1200-001',
        'city' => 'Lisboa',
    ], $overrides));
}

function referenceHoursBooking(Property $property, string $uid, array $dates): Booking
{
    $source = BookingSource::firstOrCreate(
        ['property_id' => $property->id, 'channel' => 'test', 'type' => 'url'],
        ['is_active' => true],
    );

    return Booking::create(array_merge([
        'booking_source_id' => $source->id,
        'external_uid' => $uid,
        'status' => 'reserved',
    ], $dates));
}

it('loads the property reference hours and timezone into the booking snapshot', function () {
    $property = referenceHoursProperty([
        'default_check_out_time' => '10:00',
        'default_check_in_time' => '16:00',
        'timezone' => 'Atlantic/Azores',
    ]);
    $booking = referenceHoursBooking($property, 'a', [
        'source_check_in_at' => '2026-12-01 15:00:00',
        'source_check_out_at' => '2026-12-05 11:00:00',
    ]);

    $snapshot = app(BookingDataProvider::class)->find($booking->id);

    expect($snapshot->propertySchedule)
        ->toBeInstanceOf(PropertySchedule::class)
        ->checkOutTime->toStartWith('10:00')
        ->checkInTime->toStartWith('16:00')
        ->timezone->toBe('Atlantic/Azores');
});

it('schedules the cleaning with the property hours on the adjusted check-out day', function () {
    $property = referenceHoursProperty([
        'default_check_out_time' => '10:00',
        'default_check_in_time' => '16:00',
    ]);

    // The local adjustment moves the check-out from the 5th to the 6th.
    $booking = referenceHoursBooking($property, 'current', [
        'source_check_in_at' => '2026-12-01 15:00:00',
        'source_check_out_at' => '2026-12-05 11:00:00',
        'overridden_check_out_at' => '2026-12-06 11:00:00',
    ]);
    referenceHoursBooking($property, 'next', [
        'source_check_in_at' => '2026-12-08 15:00:00',
        'source_check_out_at' => '2026-12-10 11:00:00',
    ]);

    $task = app(CreateCheckoutCleaningTask::class)->execute($booking->id);

    // Lisbon is UTC+0 in December.
    expect($task->scheduled_start_at->format('Y-m-d H:i'))->toBe('2026-12-06 10:00')
        ->and($task->scheduled_end_at->format('Y-m-d H:i'))->toBe('2026-12-08 16:00');
});

it('keeps 11:30 and 14:30 when the property has no reference hours', function () {
    $property = referenceHoursProperty();
    $booking = referenceHoursBooking($property, 'current', [
        'source_check_in_at' => '2026-12-01 15:00:00',
        'source_check_out_at' => '2026-12-05 11:00:00',
    ]);
    referenceHoursBooking($property, 'next', [
        'source_check_in_at' => '2026-12-07 15:00:00',
        'source_check_out_at' => '2026-12-09 11:00:00',
    ]);

    $task = app(CreateCheckoutCleaningTask::class)->execute($booking->id);

    expect($task)->toBeInstanceOf(Task::class)
        ->and($task->scheduled_start_at->format('Y-m-d H:i'))->toBe('2026-12-05 11:30')
        ->and($task->scheduled_end_at->format('Y-m-d H:i'))->toBe('2026-12-07 14:30');
});

it('uses the property check-in time as the inspection deadline', function () {
    Carbon::setTestNow('2026-12-05 15:30:00');
    $validator = new TaskGenerationValidator();

    $snapshot = fn (?string $checkInTime) => new BookingSnapshot(
        bookingId: 1,
        propertyId: 1,
        checkIn: Carbon::parse('2026-12-05 15:00:00'),
        checkOut: Carbon::parse('2026-12-07 11:00:00'),
        nextCheckIn: null,
        propertySchedule: new PropertySchedule('11:00', $checkInTime, 'Europe/Lisbon'),
    );

    // Deadline 16:00 has not passed yet.
    $validator->validateInspection($snapshot('16:00'));

    // Without reference hours the deadline is 14:30, which has passed.
    expect(fn () => $validator->validateInspection($snapshot(null)))
        ->toThrow(ValidationException::class, 'O prazo da inspeção (14h30 do dia do check-in) já passou.');
});
