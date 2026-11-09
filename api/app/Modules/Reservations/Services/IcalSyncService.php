<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Services;

use App\Modules\Reservations\DTO\ImportResult;
use App\Modules\Reservations\Exceptions\SyncInProgressException;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Ical\IcalFetcher;
use App\Modules\Reservations\Models\BookingSource;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Throwable;

final class IcalSyncService
{
    // Longer than the slowest possible download; frees itself if the process dies.
    private const LOCK_SECONDS = 300;

    public function __construct(
        private readonly IcalFetcher $fetcher,
        private readonly AirbnbAdapter $adapter,
        private readonly BookingImportService $importService,
    ) {
    }

    public static function lockKey(int $sourceId): string
    {
        return "reservations:sync:{$sourceId}";
    }

    public function sync(BookingSource $source): ImportResult
    {
        if ($source->type !== 'url' || ! $source->is_active) {
            throw new LogicException('Only active url sources can be synced.');
        }

        // One sync per source at a time, whether it came from the button or the scheduler.
        $lock = Cache::lock(self::lockKey($source->id), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new SyncInProgressException();
        }

        try {
            return $this->run($source);
        } finally {
            $lock->release();
        }
    }

    private function run(BookingSource $source): ImportResult
    {
        // The run is recorded before downloading, so failed downloads are logged too.
        $syncRun = $this->importService->startRun($source);

        try {
            $parseResult = $this->adapter->parseFeed(
                $this->fetcher->fetch($source->url),
                $source->property_id,
            );
        } catch (Throwable $e) {
            $this->importService->failRun($syncRun, $e);

            throw $e;
        }

        // Only a full, successful read of the live feed may cancel bookings.
        return $this->importService->importInto($source, $syncRun, $parseResult, cancelMissing: true);
    }
}