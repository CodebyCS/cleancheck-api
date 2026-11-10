<?php

namespace App\Modules\Tasks\Listeners;

use App\Modules\Reservations\Events\BookingDeleted;
use App\Modules\Tasks\Actions\DeleteTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteTasksOnBookingDeleted implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(private readonly DeleteTask $action){}

    public function handle(BookingDeleted $event): void
    {
        $action->forBooking($event->bookingId);
    }

    public function failed(BookingDeleted $event, Throwable $exception): void
    {
        Log::error('Failed to delete tasks after a booking was deleted.', [
            'booking_id' => $event->bookingId,
            'error' => $exception->getMessage(),
        ]);
    }
}