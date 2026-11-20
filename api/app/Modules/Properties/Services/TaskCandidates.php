<?php

namespace App\Modules\Properties\Services;

use App\Models\Task;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Evaluates which active service providers can take a task.
 *
 * A provider is eligible when all of these hold:
 * - the property has no zone, or the provider works in that zone;
 * - the provider's weekly hours cover the whole task window (in the property's timezone);
 * - no unavailability overlaps the task window;
 * - no other open task of the provider overlaps the task window.
 */
class TaskCandidates
{
    public const OUTSIDE_ZONE = 'outside_zone';

    public const NO_SCHEDULE = 'no_schedule';

    public const OUTSIDE_HOURS = 'outside_hours';

    public const UNAVAILABLE = 'unavailable';

    public const BUSY = 'busy';

    private const OPEN_STATUSES = ['pending', 'in_progress'];

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Task $task): array
    {
        $property = Property::query()->findOrFail($task->property_id);
        $timezone = $property->timezone ?: Property::DEFAULT_TIMEZONE;

        $start = $task->scheduled_start_at->copy()->utc();
        $end = $task->scheduled_end_at->copy()->utc();
        $localStart = $start->copy()->setTimezone($timezone);
        $localEnd = $end->copy()->setTimezone($timezone);

        $providers = $this->activeProviders($start, $end);
        $busy = $this->busyProviders($task, $start, $end);

        $candidates = $providers
            ->map(fn (ServiceProvider $provider) => $this->candidate(
                $provider,
                $property,
                $localStart,
                $localEnd,
                $busy->get($provider->id, collect()),
                $task,
            ))
            ->sortBy([
                fn (array $a, array $b) => $b['eligible'] <=> $a['eligible'],
                fn (array $a, array $b) => strcasecmp($a['name'], $b['name']),
            ])
            ->values()
            ->all();

        return [
            'task_id' => $task->id,
            'property_id' => $property->id,
            'zone_id' => $property->zone_id,
            'window' => [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'timezone' => $timezone,
                'local_date' => $localStart->toDateString(),
                'weekday' => $localStart->isoWeekday(),
                'local_start' => $localStart->format('H:i'),
                'local_end' => $localEnd->format('H:i'),
            ],
            'candidates' => $candidates,
        ];
    }

    /**
     * Active providers with only the rows needed for this window.
     *
     * @return Collection<int, ServiceProvider>
     */
    private function activeProviders(Carbon $start, Carbon $end): Collection
    {
        return ServiceProvider::query()
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->with([
                'user:id,name',
                'zones:zones.id',
                'availabilities' => fn ($query) => $query->orderBy('start_time'),
                'unavailabilities' => fn ($query) => $query
                    ->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $start)
                    ->orderBy('starts_at'),
            ])
            ->get();
    }

    /**
     * Other open tasks overlapping the window, grouped by provider.
     *
     * @return Collection<int, Collection<int, int>>
     */
    private function busyProviders(Task $task, Carbon $start, Carbon $end): Collection
    {
        return Task::query()
            ->whereKeyNot($task->id)
            ->whereNotNull('service_provider_id')
            ->whereIn('status', self::OPEN_STATUSES)
            ->where('scheduled_start_at', '<', $end)
            ->where('scheduled_end_at', '>', $start)
            ->get(['id', 'service_provider_id'])
            ->groupBy('service_provider_id')
            ->map(fn (Collection $tasks) => $tasks->pluck('id')->values());
    }

    /**
     * @param  Collection<int, int>  $conflictingTaskIds
     * @return array<string, mixed>
     */
    private function candidate(
        ServiceProvider $provider,
        Property $property,
        Carbon $localStart,
        Carbon $localEnd,
        Collection $conflictingTaskIds,
        Task $task,
    ): array {
        $reasons = [];

        // Null means the property has no zone, so the zone is not checked.
        $inZone = $property->zone_id === null
            ? null
            : $provider->zones->contains('id', $property->zone_id);

        if ($inZone === false) {
            $reasons[] = self::OUTSIDE_ZONE;
        }

        if ($provider->availabilities->isEmpty()) {
            $reasons[] = self::NO_SCHEDULE;
        } elseif (! $this->coversWindow($provider, $localStart, $localEnd)) {
            $reasons[] = self::OUTSIDE_HOURS;
        }

        $unavailability = $provider->unavailabilities->first();

        if ($unavailability) {
            $reasons[] = self::UNAVAILABLE;
        }

        if ($conflictingTaskIds->isNotEmpty()) {
            $reasons[] = self::BUSY;
        }

        return [
            'service_provider_id' => $provider->id,
            'name' => $provider->user->name,
            'eligible' => $reasons === [],
            'is_assigned' => $task->service_provider_id === $provider->id,
            'in_zone' => $inZone,
            'reasons' => $reasons,
            'unavailability' => $unavailability ? [
                'starts_at' => $unavailability->starts_at->toIso8601String(),
                'ends_at' => $unavailability->ends_at->toIso8601String(),
                'reason' => $unavailability->reason,
            ] : null,
            'conflicting_task_ids' => $conflictingTaskIds->all(),
        ];
    }

    /**
     * True when one interval of that weekday contains the whole local window.
     * A window that crosses midnight is never covered, because intervals end on the same day.
     */
    private function coversWindow(ServiceProvider $provider, Carbon $localStart, Carbon $localEnd): bool
    {
        if (! $localStart->isSameDay($localEnd)) {
            return false;
        }

        $from = $localStart->format('H:i');
        $to = $localEnd->format('H:i');

        return $provider->availabilities
            ->where('weekday', $localStart->isoWeekday())
            ->contains(fn ($interval) => substr((string) $interval->start_time, 0, 5) <= $from
                && substr((string) $interval->end_time, 0, 5) >= $to);
    }
}
