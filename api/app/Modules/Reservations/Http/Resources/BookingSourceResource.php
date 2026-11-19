<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use App\Modules\Reservations\Models\SyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BookingSourceResource extends JsonResource
{
    public const RELATIONS = ['latestSyncRun', 'latestSuccessfulSyncRun'];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'propertyId' => $this->property_id,
            'channel' => $this->channel,
            'type' => $this->type,
            'url' => $this->url,
            'isActive' => $this->is_active,
            'lastSync' => $this->syncRunData($this->latestSyncRun),
            'lastSuccessAt' => $this->latestSuccessfulSyncRun?->finished_at?->toIso8601String(),
            'stale' => $this->isStale(),
            'staleAfterMinutes' => (int) config('reservations.stale_after_minutes'),
        ];
    }

    private function syncRunData(?SyncRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'status' => $run->status,
            'startedAt' => $run->started_at->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'createdCount' => $run->created_count,
            'updatedCount' => $run->updated_count,
            'cancelledCount' => $run->cancelled_count,
            'errorMessage' => $run->error_message,
        ];
    }

    // Only active url sources sync on their own, so only they can fall behind.
    private function isStale(): bool
    {
        if ($this->type !== 'url' || ! $this->is_active) {
            return false;
        }

        $lastSuccess = $this->latestSuccessfulSyncRun?->finished_at;

        return $lastSuccess === null
            || $lastSuccess->lt(now()->subMinutes((int) config('reservations.stale_after_minutes')));
    }
}