<?php

namespace App\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceEntryResource extends JsonResource
{
    private bool $includeAdjustments = false;

    // Adds the adjustment history (used by the review detail).
    public function withAdjustments(): static
    {
        $this->includeAdjustments = true;

        return $this;
    }

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
            // Original amount, never changed; final_amount includes the adjustments.
            'amount' => $this->amount,
            'adjustments_total' => $this->adjustmentsTotal(),
            'final_amount' => $this->finalAmount(),
            'currency' => $this->currency,
            'amount_source' => $this->amount_source,
            'rate_id' => $this->rate_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'adjustments' => $this->when(
                $this->includeAdjustments,
                fn () => ServiceEntryAdjustmentResource::collection($this->adjustments),
            ),
        ];
    }
}
