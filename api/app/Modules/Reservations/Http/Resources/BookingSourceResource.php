<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BookingSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'propertyId' => $this->property_id,
            'channel' => $this->channel,
            'type' => $this->type,
            'url' => $this->url,
            'isActive' => $this->is_active,
        ];
    }
}