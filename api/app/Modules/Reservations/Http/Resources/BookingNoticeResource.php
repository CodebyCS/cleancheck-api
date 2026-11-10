<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BookingNoticeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $property = $this->booking->bookingSource->property;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'read' => $this->read_at !== null,
            'readAt' => $this->read_at?->toIso8601String(),
            'notifiedAt' => $this->updated_at->toIso8601String(),
            'booking' => [
                'id' => $this->booking_id,
                'status' => $this->booking->status->value,
                'checkIn' => $this->check_in_on->format('Y-m-d'),
                'checkOut' => $this->check_out_on->format('Y-m-d'),
            ],
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
            ],
            'task' => $this->task_id === null ? null : [
                'id' => $this->task_id,
                'available' => $this->taskIsAvailable(),
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