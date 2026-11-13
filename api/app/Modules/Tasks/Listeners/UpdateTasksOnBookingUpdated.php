<?php

namespace App\Modules\Tasks\Listeners;

use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Tasks\Actions\UpdateTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateTasksOnBookingUpdated implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(private readonly UpdateTask $action) {}

    public function handle(BookingUpdated $event): void
    {
        $this->action->forBooking($event->bookingId);
    }

    public function failed(BookingUpdated $event, Throwable $exception): void
    {
        Log::error('Failed to update tasks after a booking was updated.', [
            'booking_id' => $event->bookingId,
            'error' => $exception->getMessage(),
        ]);
    }
}