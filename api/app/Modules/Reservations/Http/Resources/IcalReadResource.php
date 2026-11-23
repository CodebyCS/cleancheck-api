<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use App\Modules\Reservations\DTO\ParseResult;
use App\Modules\Reservations\DTO\ParseWarning;
use App\Modules\Reservations\DTO\ReservationEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class IcalReadResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        ParseResult $result,
        private readonly int $propertyId,
        private readonly string $channel,
    ) {
        parent::__construct($result);
    }

    public function toArray(Request $request): array
    {
        /** @var ParseResult $result */
        $result = $this->resource;

        return [
            'propertyId' => $this->propertyId,
            'channel' => $this->channel,
            'events' => array_map(fn (ReservationEvent $event) => [
                'externalUid' => $event->externalUid,
                'checkIn' => $event->checkIn->format('Y-m-d'),
                'checkOut' => $event->checkOut->format('Y-m-d'),
                'nights' => $event->nights(),
                'status' => $event->status->value,
                'sequence' => $event->sequence,
                'reservationUrl' => $event->reservationUrl,
                'rawSummary' => $event->rawSummary,
            ], $result->events),
            'warnings' => array_map(fn (ParseWarning $warning) => [
                'uid' => $warning->uid,
                'code' => $warning->code->value,
                'message' => $warning->message,
            ], $result->warnings),
        ];
    }
}