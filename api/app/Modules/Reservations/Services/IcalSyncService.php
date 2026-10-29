<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Services;

use App\Modules\Reservations\DTO\ImportResult;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Ical\IcalFetcher;
use App\Modules\Reservations\Models\BookingSource;
use LogicException;
use Throwable;

final class IcalSyncService
{
    public function __construct(
        private readonly IcalFetcher $fetcher,
        private readonly AirbnbAdapter $adapter,
        private readonly BookingImportService $importService,
    ) {
    }

    public function sync(BookingSource $source): ImportResult
    {
        if ($source->type !== 'url' || ! $source->is_active) {
            throw new LogicException('Only active url sources can be synced.');
        }

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

        return $this->importService->importInto($source, $syncRun, $parseResult);
    }
}