<?php

namespace App\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceEntryAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_entry_id' => $this->service_entry_id,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'created_by' => $this->createdBy !== null
                ? ['id' => $this->createdBy->id, 'name' => $this->createdBy->name]
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
