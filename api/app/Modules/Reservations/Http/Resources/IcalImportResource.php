<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use App\Modules\Reservations\DTO\ImportResult;
use App\Modules\Reservations\DTO\ParseWarning;
use App\Modules\Reservations\DTO\ReservationEvent;
use App\Modules\Reservations\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class IcalImportResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        ImportResult $result,
        private readonly int $propertyId,
        private readonly string $channel,
    ) {
        parent::__construct($result);
    }

    public function toArray(Request $request): array
    {
        /** @var ImportResult $result */
        $result = $this->resource;

        return [
            'propertyId' => $this->propertyId,
            'channel' => $this->channel,
            'created' => array_map(fn (Booking $booking) => $this->booking($booking), $result->created),
            'existing' => array_map(fn (Booking $booking) => $this->booking($booking), $result->existing),
            'ambiguous' => array_map(fn (ReservationEvent $event) => $this->event($event), $result->ambiguous),
            'skipped' => array_map(fn (ReservationEvent $event) => $this->event($event), $result->skipped),
            'warnings' => array_map(fn (ParseWarning $warning) => [
                'uid' => $warning->uid,
                'code' => $warning->code->value,
                'message' => $warning->message,
            ], $result->warnings),
        ];
    }

    private function booking(Booking $booking): array
    {
        return [
            'bookingId' => $booking->id,
            'externalUid' => $booking->external_uid,
            'checkIn' => $booking->source_check_in_at->format('Y-m-d'),
            'checkOut' => $booking->source_check_out_at->format('Y-m-d'),
            'nights' => (int) $booking->source_check_in_at->diffInDays($booking->source_check_out_at),
            'status' => $booking->status->value,
        ];
    }

    private function event(ReservationEvent $event): array
    {
        return [
            'externalUid' => $event->externalUid,
            'checkIn' => $event->checkIn->format('Y-m-d'),
            'checkOut' => $event->checkOut->format('Y-m-d'),
            'status' => $event->status->value,
            'rawSummary' => $event->rawSummary,
        ];
    }
}