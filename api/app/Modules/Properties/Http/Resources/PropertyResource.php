<?php

namespace App\Modules\Properties\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'access_instructions' => $this->access_instructions,
            'responsible_service_provider_id' => $this->responsible_service_provider_id,
            'base_cleaning_amount' => $this->base_cleaning_amount,
            'default_check_in_time' => $this->formatTime($this->default_check_in_time),
            'default_check_out_time' => $this->formatTime($this->default_check_out_time),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function formatTime(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
