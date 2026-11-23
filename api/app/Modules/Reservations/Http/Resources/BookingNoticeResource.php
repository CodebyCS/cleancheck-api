<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BookingNoticeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $booking = $this->booking;
        $property = $booking->bookingSource->property;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'read' => $this->read_at !== null,
            'readAt' => $this->read_at?->toIso8601String(),
            'notifiedAt' => $this->updated_at->toIso8601String(),
            'booking' => [
                'id' => $booking->id,
                'status' => $booking->status->value,
                // Booking notices keep the dates of the change; task notices show the current stay.
                'checkIn' => ($this->check_in_on ?? $booking->overridden_check_in_at ?? $booking->source_check_in_at)
                    ->format('Y-m-d'),
                'checkOut' => ($this->check_out_on ?? $booking->overridden_check_out_at ?? $booking->source_check_out_at)
                    ->format('Y-m-d'),
            ],
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
            ],
            'task' => $this->task_id === null ? null : [
                'id' => $this->task_id,
                'available' => $this->taskIsAvailable(),
                // Window at the time of the notice (task notices only).
                'scheduledStart' => $this->scheduled_start_at?->toIso8601String(),
                'scheduledEnd' => $this->scheduled_end_at?->toIso8601String(),
            ],
            // Context saved when the notice was created. Notices older than this field have nulls.
            'change' => [
                'taskStatus' => $this->task_status,
                'executionPreserved' => (bool) $this->execution_preserved,
                'action' => $this->action?->value,
            ],
        ];
    }

    // Same rule as the task endpoints: a provider only opens tasks still assigned to them.
    private function taskIsAvailable(): bool
    {
        $task = $this->task;

        return $task !== null
            && ! $task->trashed()
            && (int) $task->serviceProvider?->user_id === (int) $this->user_id;
    }
}