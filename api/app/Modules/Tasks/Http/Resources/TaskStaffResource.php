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
            'started_at' => $this->started_at,
            'is_overdue' => $this->resource->isOverdue(),
            'service_provider_id' => $this->service_provider_id,
            'service_provider' => $this->serviceProvider?->user?->name,
            'agreed_amount' => $this->agreed_amount,
            'status' => $this->status,
            'has_video' => (bool) ($this->has_video ?? $this->videos()->exists()),
            'missing_evidence' => $this->status === 'completed'
                && $this->completedBy?->hasRole('service_provider') === true
                && $this->evidence_accepted_at === null
                && !(
                    (bool) ($this->has_video ?? $this->videos()->exists())
                    || (bool) ($this->has_evidence ?? $this->evidences()->exists())
                ),
            'evidence_accepted_at' => $this->evidence_accepted_at,
            'evidence_accepted_by' => $this->evidenceAcceptedBy?->name,
            'notes' => $this->notes,
            'provider_notes' => $this->provider_notes,
            'completed_at' => $this->completed_at,
            'completed_by' => $this->completedBy?->name,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}