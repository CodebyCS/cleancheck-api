<?php

declare(strict_types=1);

namespace App\Modules\Alerts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TaskAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $task = $this->task;
        $provider = $task?->serviceProvider;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'priority' => $this->priority->value,
            'action' => $this->action->value,
            'reason' => $this->reason,
            'active' => $this->resolved_at === null,
            'detectedAt' => $this->detected_at->toIso8601String(),
            'resolvedAt' => $this->resolved_at?->toIso8601String(),
            'resolution' => $this->resolution?->value,
            'resolutionNote' => $this->resolution_note,
            'resolvedBy' => $this->resolvedBy === null ? null : [
                'id' => $this->resolvedBy->id,
                'name' => $this->resolvedBy->name,
            ],
            'task' => $task === null ? null : [
                'id' => $task->id,
                'type' => $task->type,
                'status' => $task->status,
                'scheduledStart' => $task->scheduled_start_at?->toIso8601String(),
                'scheduledEnd' => $task->scheduled_end_at?->toIso8601String(),
                // A cancelled task stays in the history, but its page no longer exists.
                'available' => ! $task->trashed(),
            ],
            'property' => $task?->property === null ? null : [
                'id' => $task->property->id,
                'name' => $task->property->name,
            ],
            'provider' => $provider === null ? null : [
                'id' => $provider->id,
                'name' => $provider->user?->name,
            ],
        ];
    }
}