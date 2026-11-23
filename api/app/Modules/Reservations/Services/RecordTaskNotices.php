<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Services;

use App\Models\Task;
use App\Models\User;
use App\Modules\Properties\Models\ServiceProvider;
use App\Modules\Reservations\Enums\BookingNoticeType;
use App\Modules\Reservations\Enums\NoticeAction;
use App\Modules\Reservations\Models\BookingNotice;

final class RecordTaskNotices
{
    /** The new provider gets the task; the previous one is told it left their list. */
    public function assigned(int $taskId, ?int $previousProviderId, int $newProviderId, int $assignmentId): void
    {
        $task = Task::withTrashed()->find($taskId);
        if ($task === null) {
            return;
        }

        // One row of task_assignment_history = one change = one notice per person.
        $dedupKey = "task_assignment:{$assignmentId}";

        $this->notify($task, $newProviderId, BookingNoticeType::TaskAssigned, $dedupKey, NoticeAction::OpenTask);

        if ($previousProviderId !== null) {
            $this->notify($task, $previousProviderId, BookingNoticeType::TaskUnassigned, $dedupKey, NoticeAction::None);
        }
    }

    public function cancelled(int $taskId, ?int $providerId): void
    {
        $task = Task::withTrashed()->find($taskId);
        if ($task === null || $providerId === null) {
            return;
        }

        // A task is cancelled only once.
        $this->notify($task, $providerId, BookingNoticeType::TaskCancelled, "task_cancelled:{$task->id}", NoticeAction::None);
    }

    private function notify(
        Task $task,
        int $providerId,
        BookingNoticeType $type,
        string $dedupKey,
        NoticeAction $action,
    ): void {
        $userId = ServiceProvider::query()->whereKey($providerId)->value('user_id');

        // Deactivated accounts cannot sign in, so they get no notices.
        if ($userId === null || ! User::query()->whereKey($userId)->where('is_active', true)->exists()) {
            return;
        }

        $now = now();

        // The unique (user_id, dedup_key) index turns a repeated event (job retry) into a no-op.
        BookingNotice::query()->insertOrIgnore([
            'user_id' => $userId,
            'booking_id' => $task->booking_id,
            'task_id' => $task->id,
            // Lets the previous provider know a task they had started was reassigned.
            'task_status' => $task->status,
            'execution_preserved' => false,
            'action' => $action->value,
            'type' => $type->value,
            'dedup_key' => $dedupKey,
            // Stay dates stay empty: task notices are deduplicated by dedup_key only.
            'check_in_on' => null,
            'check_out_on' => null,
            'scheduled_start_at' => $task->scheduled_start_at,
            'scheduled_end_at' => $task->scheduled_end_at,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}