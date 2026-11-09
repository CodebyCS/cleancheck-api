<?php

namespace App\Modules\Tasks\Listeners;

use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Tasks\Actions\CancelTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class CancelTasksOnBookingCancelled implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function handle(BookingCancelled $event, CancelTask $action): void
    {
        $action->forBooking($event->bookingId);
    }

    public function failed(BookingCancelled $event, Throwable $exception): void
    {
        Log::error('Failed to cancel tasks after a booking was cancelled.', [
            'booking_id' => $event->bookingId,
            'error' => $exception->getMessage(),
        ]);
    }
}