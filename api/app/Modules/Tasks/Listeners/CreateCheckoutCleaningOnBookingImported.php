<?php

namespace App\Modules\Tasks\Listeners;

use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Tasks\Actions\CreateCheckoutCleaningTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateCheckoutCleaningOnBookingImported implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public $backoff = [10, 60];

    public function handle(BookingImported $event, CreateCheckoutCleaningTask $action): void
    {
        try {
            $action->execute($event->bookingId);
        } catch (ValidationException $e) {
            Log::warning('Task de checkout não criada.', [
                'booking_id' => $event->bookingId,
                'errors'     => $e->errors(),
            ]);
        }
    }

    public function failed(BookingImported $event, Throwable $e): void
    {
        Log::error('Falha definitiva ao criar a task de checkout.', [
            'booking_id' => $event->bookingId,
            'error'      => $e->getMessage(),
        ]);
    }
}