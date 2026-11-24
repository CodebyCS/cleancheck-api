<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Services;

use App\Models\Task;
use App\Models\User;
use App\Modules\Reservations\Enums\BookingNoticeType;
use App\Modules\Reservations\Enums\NoticeAction;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingNotice;
use Illuminate\Support\Collection;

final class RecordBookingNotices
{
    private const STAFF_ROLES = ['admin', 'supervisor'];

    // Booking changes never touch these tasks (see UpdateTask / CancelTask::forBooking).
    private const STARTED_STATUSES = ['in_progress', 'completed'];

    private const BOOKING_TYPES = ['booking_updated', 'booking_cancelled'];

    public function record(int $bookingId, BookingNoticeType $type): void
    {
        $booking = Booking::find($bookingId);
        if ($booking === null) {
            return;
        }

        $tasks = Task::withTrashed()
            ->where('booking_id', $booking->id)
            ->with('serviceProvider')
            ->orderBy('id')
            ->get();

        $recipients = $this->recipients($tasks);
        if ($recipients === []) {
            return;
        }

        $checkIn = ($booking->overridden_check_in_at ?? $booking->source_check_in_at)->toDateString();
        $checkOut = ($booking->overridden_check_out_at ?? $booking->source_check_out_at)->toDateString();
        $change = "{$type->value}|{$checkIn}|{$checkOut}";

        $startedTaskKept = $tasks->contains(
            fn (Task $task) => in_array($task->status, self::STARTED_STATUSES, true),
        );
        $latest = $this->latestChanges($booking->id, array_keys($recipients));

        $rows = [];
        foreach ($recipients as $userId => $task) {
            // The same change processed again (sync retry, repeated job): the person already has it.
            if (($latest[$userId] ?? null) === $change) {
                continue;
            }

            // Staff have no task of their own: they are told if any started task was kept.
            $preserved = $task === null
                ? $startedTaskKept
                : in_array($task->status, self::STARTED_STATUSES, true);

            $rows[] = [
                'user_id' => $userId,
                'booking_id' => $booking->id,
                'task_id' => $task?->id,
                'task_status' => $task?->status,
                'execution_preserved' => $preserved,
                'action' => $this->action($type, $task, $preserved)->value,
                'type' => $type->value,
                'check_in_on' => $checkIn,
                'check_out_on' => $checkOut,
                'read_at' => null,
            ];
        }

        if ($rows === []) {
            return;
        }

        // A real change back to earlier dates (A -> B -> A) shows that notice as unread again.
        BookingNotice::query()->upsert(
            $rows,
            ['user_id', 'booking_id', 'type', 'check_in_on', 'check_out_on'],
            ['task_id', 'task_status', 'execution_preserved', 'action', 'read_at'],
        );
    }

    /**
     * Last booking change each person was told about, as "type|checkIn|checkOut".
     *
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    private function latestChanges(int $bookingId, array $userIds): array
    {
        return BookingNotice::query()
            ->where('booking_id', $bookingId)
            ->whereIn('user_id', $userIds)
            ->whereIn('type', self::BOOKING_TYPES)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get(['user_id', 'type', 'check_in_on', 'check_out_on'])
            // Ascending order: the most recent notice of each person wins.
            ->mapWithKeys(fn (BookingNotice $notice) => [
                $notice->user_id => sprintf(
                    '%s|%s|%s',
                    $notice->type->value,
                    $notice->check_in_on->toDateString(),
                    $notice->check_out_on->toDateString(),
                ),
            ])
            ->all();
    }

    private function action(BookingNoticeType $type, ?Task $task, bool $preserved): NoticeAction
    {
        if ($task === null) {
            return $preserved ? NoticeAction::ReviewStartedTask : NoticeAction::None;
        }

        return match (true) {
            $task->status === 'completed' => NoticeAction::None,
            $task->status === 'in_progress' && $type === BookingNoticeType::BookingCancelled => NoticeAction::ContactSupervisor,
            $task->status === 'in_progress' => NoticeAction::ContinueTask,
            $type === BookingNoticeType::BookingUpdated => NoticeAction::CheckNewSchedule,
            default => NoticeAction::None,
        };
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array<int, Task|null> recipient user id => task shown in the notice (null for staff)
     */
    private function recipients(Collection $tasks): array
    {
        $recipients = [];

        foreach ($tasks as $task) {
            $userId = $task->serviceProvider?->user_id;
            if ($userId !== null && ! array_key_exists($userId, $recipients)) {
                $recipients[$userId] = $task;
            }
        }

        $staffIds = User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('name', self::STAFF_ROLES))
            ->pluck('id');

        foreach ($staffIds as $userId) {
            if (! array_key_exists($userId, $recipients)) {
                $recipients[$userId] = null;
            }
        }

        $activeIds = User::query()
            ->whereIn('id', array_keys($recipients))
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        return array_intersect_key($recipients, array_flip($activeIds));
    }
}