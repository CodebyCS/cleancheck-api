<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Services;

use App\Modules\Reservations\DTO\ImportResult;
use App\Modules\Reservations\DTO\ParseResult;
use App\Modules\Reservations\DTO\ParseWarning;
use App\Modules\Reservations\DTO\ReservationEvent;
use App\Modules\Reservations\Enums\IcalWarningCode;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Models\SyncRun;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BookingImportService
{
    public function import(int $propertyId, string $channel, ParseResult $result): ImportResult
    {
        $source = BookingSource::firstOrCreate(
            ['property_id' => $propertyId, 'channel' => $channel, 'type' => 'file'],
            ['is_active' => true],
        );

        return $this->importInto($source, $this->startRun($source), $result);
    }

    public function startRun(BookingSource $source): SyncRun
    {
        return SyncRun::create([
            'booking_source_id' => $source->id,
            'started_at' => now(),
            'status' => 'running',
        ]);
    }

    public function failRun(SyncRun $syncRun, Throwable $e): void
    {
        $syncRun->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $e->getMessage(),
        ]);
    }

    public function importInto(
        BookingSource $source,
        SyncRun $syncRun,
        ParseResult $result,
        bool $cancelMissing = false,
    ): ImportResult {
        try {
            $importResult = DB::transaction(fn () => $this->persist($source, $result, $cancelMissing));
        } catch (Throwable $e) {
            $this->failRun($syncRun, $e);

            throw $e;
        }

        $syncRun->update([
            'status' => 'success',
            'finished_at' => now(),
            'created_count' => count($importResult->created),
            'cancelled_count' => count($importResult->cancelled),
        ]);

        // Existing bookings are announced too, so a reimport recovers any task that failed before.
        foreach (array_merge($importResult->created, $importResult->existing) as $booking) {
            BookingImported::dispatch($booking->id);
        }

        foreach ($importResult->cancelled as $booking) {
            BookingCancelled::dispatch($booking->id);
        }

        return $importResult;
    }

    private function persist(BookingSource $source, ParseResult $result, bool $cancelMissing): ImportResult
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

        $cancelled = $cancelMissing ? $this->cancelMissing($source, $result) : [];

        return new ImportResult($created, $existing, $ambiguous, $skipped, $result->warnings, $cancelled);
    }

    /** @return Booking[] */
    private function cancelMissing(BookingSource $source, ParseResult $result): array
    {
        // An empty feed or an event without UID may be an incomplete read: cancel nothing.
        if ($result->events === [] || $this->hasEventWithoutUid($result)) {
            return [];
        }

        // A UID counts as present even when its event was ignored with a warning.
        $presentUids = array_merge(
            array_map(fn (ReservationEvent $event) => $event->externalUid, $result->events),
            array_filter(array_map(fn (ParseWarning $warning) => $warning->uid, $result->warnings)),
        );

        // Past stays leave the Airbnb feed on their own, so only future check-outs are cancelled.
        $bookings = Booking::where('booking_source_id', $source->id)
            ->where('status', ReservationStatus::Reserved)
            ->whereNotIn('external_uid', $presentUids)
            ->whereDate('source_check_out_at', '>', today())
            ->get();

        foreach ($bookings as $booking) {
            $booking->update(['status' => ReservationStatus::Cancelled]);
        }

        return $bookings->all();
    }

    private function hasEventWithoutUid(ParseResult $result): bool
    {
        foreach ($result->warnings as $warning) {
            if ($warning->code === IcalWarningCode::MissingUid) {
                return true;
            }
        }

        return false;
    }
}