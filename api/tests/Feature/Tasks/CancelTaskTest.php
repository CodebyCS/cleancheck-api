<?php

use App\Models\Task;
use App\Modules\Tasks\Actions\CancelTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function makeBookingForCancellationTest(): int
{
    $now = now();

    $propertyId = DB::table('properties')->insertGetId([
        'name' => 'Casa para teste de cancelamento',
        'address' => 'Rua de teste, 20',
        'postal_code' => '1000-020',
        'city' => 'Lisboa',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $bookingSourceId = DB::table('booking_sources')->insertGetId([
        'property_id' => $propertyId,
        'channel' => 'test',
        'type' => 'url',
        'is_active' => true,
    ]);

    return DB::table('bookings')->insertGetId([
        'booking_source_id' => $bookingSourceId,
        'external_uid' => 'cancel-task-test-' . uniqid(),
        'source_check_in_at' => $now->copy()->addDay(),
        'source_check_out_at' => $now->copy()->addDays(2),
        'status' => 'confirmed',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function makeTaskForCancellationTest(
    int $bookingId,
    string $type = 'checkout_cleaning',
    string $status = 'pending',
): Task {
    $booking = DB::table('bookings')
        ->join('booking_sources', 'bookings.booking_source_id', '=', 'booking_sources.id')
        ->where('bookings.id', $bookingId)
        ->select('booking_sources.property_id')
        ->first();

    return Task::create([
        'booking_id' => $bookingId,
        'property_id' => $booking->property_id,
        'type' => $type,
        'status' => $status,
        'scheduled_start_at' => now(),
    ]);
}

it('marks a pending task as cancelled and soft deletes it when cancelled manually', function () {
    $bookingId = makeBookingForCancellationTest();
    $task = makeTaskForCancellationTest($bookingId);

    app(CancelTask::class)->manual($task->id);
    $cancelledTask = Task::withTrashed()->findOrFail($task->id);

    expect($cancelledTask->status)->toBe('cancelled')
        ->and($cancelledTask->deleted_at)->not->toBeNull();

    $this->assertSoftDeleted('tasks', ['id' => $task->id]);
});

it('treats repeating a manual cancellation as a no-op without changing deleted_at', function () {
    $bookingId = makeBookingForCancellationTest();
    $task = makeTaskForCancellationTest($bookingId);
    $action = app(CancelTask::class);

    $action->manual($task->id);
    $deletedAt = Task::withTrashed()->findOrFail($task->id)->deleted_at;

    $this->travel(1)->minutes();
    $action->manual($task->id);

    $cancelledTask = Task::withTrashed()->findOrFail($task->id);

    expect($cancelledTask->status)->toBe('cancelled')
        ->and($cancelledTask->deleted_at->equalTo($deletedAt))->toBeTrue();
});

it('does not convert a task previously soft deleted by another operation into cancelled', function () {
    $bookingId = makeBookingForCancellationTest();
    $task = makeTaskForCancellationTest($bookingId);
    $task->delete();

    $deletedTask = Task::withTrashed()->findOrFail($task->id);
    $deletedAt = $deletedTask->deleted_at;

    try {
        app(CancelTask::class)->manual($task->id);
        test()->fail('A tarefa apagada por outra operação devia rejeitar o cancelamento.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('status');
    }

    $unchangedTask = Task::withTrashed()->findOrFail($task->id);

    expect($unchangedTask->status)->toBe('pending')
        ->and($unchangedTask->deleted_at->equalTo($deletedAt))->toBeTrue();

    $this->assertSoftDeleted('tasks', ['id' => $task->id]);
});

it('rejects manual cancellation of tasks that are in progress or completed', function () {
    foreach (['in_progress', 'completed'] as $status) {
        $bookingId = makeBookingForCancellationTest();
        $task = makeTaskForCancellationTest($bookingId, status: $status);

        try {
            app(CancelTask::class)->manual($task->id);
            test()->fail("A tarefa {$status} devia rejeitar o cancelamento.");
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('status');
        }

        expect($task->fresh()->status)->toBe($status)
            ->and($task->fresh()->deleted_at)->toBeNull();
    }
});

it('cancels pending tasks from a booking and preserves in-progress and completed tasks', function () {
    $bookingId = makeBookingForCancellationTest();
    $pendingTask = makeTaskForCancellationTest($bookingId, 'checkout_cleaning', 'pending');
    $inProgressTask = makeTaskForCancellationTest($bookingId, 'checkin_inspection', 'in_progress');

    $anotherBookingId = makeBookingForCancellationTest();
    $completedTask = makeTaskForCancellationTest($anotherBookingId, 'checkout_cleaning', 'completed');

    $action = app(CancelTask::class);
    $action->forBooking($bookingId);
    $action->forBooking($bookingId);
    $action->forBooking($anotherBookingId);
    $cancelledPendingTask = Task::withTrashed()->findOrFail($pendingTask->id);

    expect($cancelledPendingTask->status)->toBe('cancelled')
        ->and($cancelledPendingTask->deleted_at)->not->toBeNull()
        ->and($inProgressTask->fresh()->status)->toBe('in_progress')
        ->and($inProgressTask->fresh()->deleted_at)->toBeNull()
        ->and($completedTask->fresh()->status)->toBe('completed')
        ->and($completedTask->fresh()->deleted_at)->toBeNull();

    $this->assertSoftDeleted('tasks', ['id' => $pendingTask->id]);
});
