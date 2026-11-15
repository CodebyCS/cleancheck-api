<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $checkIn = $this->overridden_check_in_at ?? $this->source_check_in_at;
        $checkOut = $this->overridden_check_out_at ?? $this->source_check_out_at;

        return [
            'id' => $this->id,
            'propertyId' => $this->bookingSource->property_id,
            'bookingSourceId' => $this->booking_source_id,
            'externalUid' => $this->external_uid,
            'status' => $this->status->value,
            'source' => [
                'checkIn' => $this->source_check_in_at->format('Y-m-d'),
                'checkOut' => $this->source_check_out_at->format('Y-m-d'),
            ],
            'overridden' => [
                'checkIn' => $this->overridden_check_in_at?->format('Y-m-d'),
                'checkOut' => $this->overridden_check_out_at?->format('Y-m-d'),
            ],
            'effective' => [
                'checkIn' => $checkIn->format('Y-m-d'),
                'checkOut' => $checkOut->format('Y-m-d'),
            ],
        ];
    }
}