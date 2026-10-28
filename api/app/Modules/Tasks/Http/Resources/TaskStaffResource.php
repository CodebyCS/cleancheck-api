<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskStaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'property' => $this->property?->name,
            'property_address' => $this->property?->address,
            'type' => $this->type,
            'scheduled_start_at' => $this->scheduled_start_at,
            'scheduled_end_at' => $this->scheduled_end_at,
            'service_provider_id' => $this->service_provider_id,
            'service_provider' => $this->serviceProvider?->user?->name,
            'agreed_amount' => $this->agreed_amount,
            'status' => $this->status,
            'deleted_at' => $this->deleted_at,
        ];
    }
}