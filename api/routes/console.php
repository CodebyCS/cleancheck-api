<?php

use App\Modules\Reservations\Jobs\SyncBookingSource;
use App\Modules\Reservations\Models\BookingSource;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reservations:sync {--now : Sync in this process instead of queueing}', function () {
    $sourceIds = BookingSource::where('type', 'url')->where('is_active', true)->pluck('id');

    foreach ($sourceIds as $sourceId) {
        if ($this->option('now')) {
            SyncBookingSource::dispatchSync($sourceId);
        } else {
            SyncBookingSource::dispatch($sourceId);
        }
    }

    $this->info("{$sourceIds->count()} fonte(s) iCal enviada(s) para sincronização.");
})->purpose('Sync every active iCal url source');

Schedule::command('reservations:sync')->everyThirtyMinutes()->withoutOverlapping();