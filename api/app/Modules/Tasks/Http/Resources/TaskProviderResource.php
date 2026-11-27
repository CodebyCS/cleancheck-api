<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property' => $this->property?->name,
            'property_address' => $this->property?->address,
            'access_instructions' => $this->property?->access_instructions,
            'type' => $this->type,
            'scheduled_start_at' => $this->scheduled_start_at,
            'scheduled_end_at' => $this->scheduled_end_at,
            'is_overdue' => $this->resource->isOverdue(),
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
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'provider_notes' => $this->provider_notes,
        ];
    }
}