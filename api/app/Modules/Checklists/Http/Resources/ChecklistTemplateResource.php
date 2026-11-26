<?php

namespace App\Modules\Checklists\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChecklistTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_type' => $this->task_type,
            'version' => $this->version,
            'name' => $this->name,
            'is_current' => (bool) ($this->is_current ?? false),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'position' => $item->position,
                'label' => $item->label,
                'required' => $item->is_required,
            ])->values()),
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
