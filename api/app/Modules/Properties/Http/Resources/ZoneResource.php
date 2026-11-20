<?php

namespace App\Modules\Properties\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            // Counts are present only when loaded with withCount().
            'properties_count' => $this->whenCounted('properties'),
            'service_providers_count' => $this->whenCounted('serviceProviders'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
