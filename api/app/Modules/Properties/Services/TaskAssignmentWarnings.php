<?php

namespace App\Modules\Properties\Services;

use App\Models\Task;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Open tasks (pending or in progress) that need attention from management.
 * Warnings are computed on each request, so they disappear as soon as the cause is fixed.
 */
class TaskAssignmentWarnings
{
    public const NO_DEFAULT_PROVIDER = 'no_default_provider';

    public const DEFAULT_PROVIDER_INACTIVE = 'default_provider_inactive';

    public const UNASSIGNED = 'unassigned';

    public const PROVIDER_INACTIVE = 'provider_inactive';

    public const SCHEDULE_CONFLICT = 'schedule_conflict';

    private const OPEN_STATUSES = ['pending', 'in_progress'];

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?int $propertyId = null): array
    {
        $tasks = Task::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->orderBy('scheduled_start_at')
            ->orderBy('id')
            ->get(['id', 'property_id', 'service_provider_id', 'type', 'status', 'scheduled_start_at', 'scheduled_end_at']);

        $properties = Property::query()
            ->whereIn('id', $tasks->pluck('property_id')->unique())
            ->get(['id', 'name', 'responsible_service_provider_id'])
            ->keyBy('id');

        $activeProviderIds = ServiceProvider::query()
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->pluck('id')
            ->flip();

        // Conflicts are checked against all open tasks of the provider, not only the filtered ones.
        $conflicts = $this->conflicts($tasks->pluck('service_provider_id')->filter()->unique());

        return $tasks
            ->map(function (Task $task) use ($properties, $activeProviderIds, $conflicts) {
                $property = $properties->get($task->property_id);
                $defaultProviderId = $property?->responsible_service_provider_id;
                $warnings = [];

                if ($task->service_provider_id === null) {
                    $warnings[] = ['code' => match (true) {
                        $defaultProviderId === null => self::NO_DEFAULT_PROVIDER,
                        ! $activeProviderIds->has($defaultProviderId) => self::DEFAULT_PROVIDER_INACTIVE,
                        default => self::UNASSIGNED,
                    }];
                } else {
                    if (! $activeProviderIds->has($task->service_provider_id)) {
                        $warnings[] = ['code' => self::PROVIDER_INACTIVE];
                    }

                    $conflictingIds = $conflicts->get($task->id, []);

                    if ($conflictingIds !== []) {
                        $warnings[] = ['code' => self::SCHEDULE_CONFLICT, 'conflicting_task_ids' => $conflictingIds];
                    }
                }

                if ($warnings === []) {
                    return null;
                }

                return [
                    'task_id' => $task->id,
                    'type' => $task->type,
                    'status' => $task->status,
                    'scheduled_start_at' => $task->scheduled_start_at?->toIso8601String(),
                    'scheduled_end_at' => $task->scheduled_end_at?->toIso8601String(),
                    'property_id' => $task->property_id,
                    'property_name' => $property?->name,
                    'service_provider_id' => $task->service_provider_id,
                    'default_service_provider_id' => $defaultProviderId,
                    'warnings' => $warnings,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Open tasks of the same provider whose windows overlap, keyed by task id.
     *
     * @param  Collection<int, int>  $providerIds
     * @return Collection<int, list<int>>
     */
    private function conflicts(Collection $providerIds): Collection
    {
        if ($providerIds->isEmpty()) {
            return collect();
        }

        $byProvider = Task::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereIn('service_provider_id', $providerIds)
            ->whereNotNull('scheduled_start_at')
            ->get(['id', 'service_provider_id', 'scheduled_start_at', 'scheduled_end_at'])
            ->groupBy('service_provider_id');

        $result = [];

        foreach ($byProvider as $tasks) {
            foreach ($tasks as $task) {
                foreach ($tasks as $other) {
                    if ($task->id !== $other->id && $this->overlaps($task, $other)) {
                        $result[$task->id][] = $other->id;
                    }
                }
            }
        }

        return collect($result);
    }

    // A task without an end is treated as a single moment at its start.
    private function overlaps(Task $a, Task $b): bool
    {
        $aEnd = $a->scheduled_end_at ?? $a->scheduled_start_at;
        $bEnd = $b->scheduled_end_at ?? $b->scheduled_start_at;

        return $this->before($a->scheduled_start_at, $bEnd) && $this->before($b->scheduled_start_at, $aEnd);
    }

    private function before(Carbon $start, Carbon $end): bool
    {
        return $start->lt($end);
    }
}
