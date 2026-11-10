<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Services;

use App\Models\Task;
use App\Models\User;
use App\Modules\Reservations\Enums\BookingNoticeType;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingNotice;

final class RecordBookingNotices
{
    private const STAFF_ROLES = ['admin', 'supervisor'];

    public function record(int $bookingId, BookingNoticeType $type): void
    {
        $booking = Booking::find($bookingId);
        if ($booking === null) {
            return;
        }

        $recipients = $this->recipients($booking->id);
        if ($recipients === []) {
            return;
        }

        $checkIn = ($booking->overridden_check_in_at ?? $booking->source_check_in_at)->toDateString();
        $checkOut = ($booking->overridden_check_out_at ?? $booking->source_check_out_at)->toDateString();

        $rows = [];
        foreach ($recipients as $userId => $taskId) {
            $rows[] = [
                'user_id' => $userId,
                'booking_id' => $booking->id,
                'task_id' => $taskId,
                'type' => $type->value,
                'check_in_on' => $checkIn,
                'check_out_on' => $checkOut,
                'read_at' => null,
            ];
        }

        // Repeating the same change never adds a row; it only shows the notice as unread again.
        BookingNotice::query()->upsert(
            $rows,
            ['user_id', 'booking_id', 'type', 'check_in_on', 'check_out_on'],
            ['task_id', 'read_at'],
        );
    }

    /**
     * @return array<int, int|null> recipient user id => task id shown in the notice
     */
    private function recipients(int $bookingId): array
    {
        $recipients = [];

        $tasks = Task::withTrashed()
            ->where('booking_id', $bookingId)
            ->whereNotNull('service_provider_id')
            ->with('serviceProvider')
            ->orderBy('id')
            ->get();

        foreach ($tasks as $task) {
            $userId = $task->serviceProvider?->user_id;
            if ($userId !== null && ! isset($recipients[$userId])) {
                $recipients[$userId] = $task->id;
            }
        }

        $staffIds = User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('name', self::STAFF_ROLES))
            ->pluck('id');

        foreach ($staffIds as $userId) {
            $recipients[$userId] ??= null;
        }

        $activeIds = User::query()
            ->whereIn('id', array_keys($recipients))
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        return array_intersect_key($recipients, array_flip($activeIds));
    }
}