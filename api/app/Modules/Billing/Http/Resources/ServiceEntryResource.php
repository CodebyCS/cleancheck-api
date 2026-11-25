<?php

namespace App\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'service_provider_id' => $this->service_provider_id,
            'service_provider_name' => $this->whenLoaded('serviceProvider', fn () => $this->serviceProvider->user?->name),
            'property_id' => $this->property_id,
            'property_name' => $this->whenLoaded('property', fn () => $this->property->name),
            'task_type' => $this->task_type,
            'service_date' => $this->service_date?->toDateString(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'amount_source' => $this->amount_source,
            'rate_id' => $this->rate_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
