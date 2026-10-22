<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Services;

use App\Modules\Reservations\DTO\ImportResult;
use App\Modules\Reservations\DTO\ParseResult;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Models\SyncRun;
use App\Modules\Tasks\Actions\CreateCheckoutCleaningTask;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BookingImportService
{
    public function __construct(
        private readonly CreateCheckoutCleaningTask $createCleaningTask,
    ) {}

    public function import(int $propertyId, string $channel, ParseResult $result): ImportResult
    {
        $source = BookingSource::firstOrCreate(
            ['property_id' => $propertyId, 'channel' => $channel, 'type' => 'file'],
            ['is_active' => true],
        );

        $syncRun = SyncRun::create([
            'booking_source_id' => $source->id,
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $importResult = DB::transaction(fn () => $this->persist($source, $result));
        } catch (Throwable $e) {
            $syncRun->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        $syncRun->update([
            'status' => 'success',
            'finished_at' => now(),
            'created_count' => count($importResult->created),
        ]);

        return $importResult;
    }

    private function persist(BookingSource $source, ParseResult $result): ImportResult
    {
        $created = [];
        $existing = [];
        $ambiguous = [];
        $skipped = [];

        foreach ($result->events as $event) {
            if ($event->status === ReservationStatus::Unknown) {
                $ambiguous[] = $event;

                continue;
            }

            if ($event->status !== ReservationStatus::Reserved) {
                $skipped[] = $event;

                continue;
            }

            $booking = Booking::firstOrCreate(
                [
                    'booking_source_id' => $source->id,
                    'external_uid' => $event->externalUid,
                ],
                [
                    'source_check_in_at' => $event->checkIn,
                    'source_check_out_at' => $event->checkOut,
                    'status' => $event->status,
                ],
            );

            if ($booking->wasRecentlyCreated) {
                $created[] = $booking;
            } else {
                $existing[] = $booking;
            }
        }

        // Second pass: every booking is saved first, so each task can find the next check-in.
        foreach (array_merge($created, $existing) as $booking) {
            $this->createCleaningTask->execute($booking->id);
        }

        return new ImportResult($created, $existing, $ambiguous, $skipped, $result->warnings);
    }
}s