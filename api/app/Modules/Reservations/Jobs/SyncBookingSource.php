<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Jobs;

use App\Modules\Reservations\Exceptions\IcalFetchException;
use App\Modules\Reservations\Exceptions\InvalidIcalException;
use App\Modules\Reservations\Exceptions\SyncInProgressException;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Services\IcalSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SyncBookingSource implements ShouldQueue
{
    use Queueable;

    // A failed sync is already recorded in sync_runs; the next scheduled run tries again.
    public int $tries = 1;

    public function __construct(public readonly int $bookingSourceId)
    {
    }

    public function handle(IcalSyncService $syncService): void
    {
        $source = BookingSource::find($this->bookingSourceId);

        // The source may have been removed or disabled after the job was queued.
        if ($source === null || $source->type !== 'url' || ! $source->is_active) {
            return;
        }

        try {
            $syncService->sync($source);
        } catch (IcalFetchException|InvalidIcalException|SyncInProgressException $e) {
            Log::warning('iCal sync failed', [
                'booking_source_id' => $source->id,
                'code' => $e->errorCode->value,
            ]);
        }
    }
}