<?php

declare(strict_types=1);

use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Models\BookingNotice;
use App\Modules\Tasks\Actions\CancelTask;
use App\Modules\Tasks\Actions\UpdateTask;
use App\Modules\Tasks\Events\TaskAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = userWithRole('admin');
    $this->booking = noticeBooking(icalProperty()->id);
    $this->oldProvider = createServiceProvider('old.task-notice@cleancheck.test');
    $this->newProvider = createServiceProvider('new.task-notice@cleancheck.test');
    $this->task = noticeTask($this->booking, $this->oldProvider->id);
});

function reassignForNotice(int $taskId, int $providerId, int $byUserId): void
{
    app(UpdateTask::class)->manual($taskId, ['service_provider_id' => $providerId], $byUserId);
}

/** @return list<string> notice types of one user, oldest first */
function taskNoticeTypes(int $userId): array
{
    return BookingNotice::where('user_id', $userId)
        ->orderBy('id')
        ->get()
        ->map(fn (BookingNotice $notice) => $notice->type->value)
        ->all();
}

it('notifies the new and the previous provider when a task is reassigned', function () {
    reassignForNotice($this->task->id, $this->newProvider->id, $this->admin->id);

    $notice = BookingNotice::where('user_id', $this->newProvider->user_id)->firstOrFail();

    expect(taskNoticeTypes($this->newProvider->user_id))->toBe(['task_assigned'])
        ->and(taskNoticeTypes($this->oldProvider->user_id))->toBe(['task_unassigned'])
        ->and(taskNoticeTypes($this->admin->id))->toBe([])
        ->and($notice->task_id)->toBe($this->task->id)
        ->and($notice->booking_id)->toBe($this->booking->id)
        ->and($notice->dedup_key)->toStartWith('task_assignment:')
        ->and($notice->scheduled_start_at->format('Y-m-d H:i'))->toBe('2026-11-05 11:30');
});

it('creates no new notice when the same reassignment is repeated', function () {
    reassignForNotice($this->task->id, $this->newProvider->id, $this->admin->id);
    reassignForNotice($this->task->id, $this->newProvider->id, $this->admin->id);

    expect(BookingNotice::count())->toBe(2);
});

it('creates no new notice when the same event is processed twice', function () {
    reassignForNotice($this->task->id, $this->newProvider->id, $this->admin->id);
    $assignment = $this->task->assignmentHistory()->latest('id')->firstOrFail();

    // Same as a queued job running again after a failure.
    event(new TaskAssigned($this->task->id, $this->oldProvider->id, $this->newProvider->id, $assignment->id));

    expect(BookingNotice::count())->toBe(2);
});

it('gives each real reassignment its own notice', function () {
    reassignForNotice($this->task->id, $this->newProvider->id, $this->admin->id);
    reassignForNotice($this->task->id, $this->oldProvider->id, $this->admin->id);

    expect(taskNoticeTypes($this->oldProvider->user_id))->toBe(['task_unassigned', 'task_assigned'])
        ->and(taskNoticeTypes($this->newProvider->user_id))->toBe(['task_assigned', 'task_unassigned']);
});

it('reassigns from a deactivated provider without notifying them', function () {
    $this->oldProvider->user->forceFill(['is_active' => false])->save();

    reassignForNotice($this->task->id, $this->newProvider->id, $this->admin->id);

    expect(taskNoticeTypes($this->newProvider->user_id))->toBe(['task_assigned'])
        ->and(taskNoticeTypes($this->oldProvider->user_id))->toBe([]);
});

it('notifies the provider once when the team cancels the task', function () {
    app(CancelTask::class)->manual($this->task->id);
    app(CancelTask::class)->manual($this->task->id);

    expect(taskNoticeTypes($this->oldProvider->user_id))->toBe(['task_cancelled']);
});

it('leaves a cancelled booking to the booking notice', function () {
    $this->booking->update(['status' => ReservationStatus::Cancelled]);

    event(new BookingCancelled($this->booking->id));

    expect(taskNoticeTypes($this->oldProvider->user_id))->toBe(['booking_cancelled']);
});