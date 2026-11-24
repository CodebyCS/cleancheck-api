<?php

use App\Modules\Reservations\Jobs\SyncBookingSource;
use App\Models\EvidenceTrial;
use App\Modules\Reservations\Models\BookingSource;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

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
Artisan::command('evidence-trials:prune', function () {
    $disk = Storage::disk('local');
    $cutoff = now()->subDays(30);
    $removed = 0;

    EvidenceTrial::query()
        ->where('received_at', '<=', $cutoff)
        ->orderBy('id')
        ->chunkById(100, function ($trials) use ($disk, &$removed): void {
            foreach ($trials as $trial) {
                if ($disk->exists($trial->path) && !$disk->delete($trial->path)) {
                    continue;
                }

                $trial->delete();
                $removed++;
            }
        });

    foreach ($disk->allFiles('evidence-trials') as $path) {
        if ($disk->lastModified($path) <= $cutoff->timestamp && $disk->delete($path)) {
            $removed++;
        }
    }

    $this->info("{$removed} ficheiro(s)/registo(s) de ensaio removido(s).");
})->purpose('Remove evidence trial files and records older than 30 days');

Schedule::command('evidence-trials:prune')->hourly()->withoutOverlapping();