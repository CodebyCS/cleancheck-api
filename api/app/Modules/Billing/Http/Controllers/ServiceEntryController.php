<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Modules\Billing\Http\Requests\ListServiceEntriesRequest;
use App\Modules\Billing\Http\Resources\ServiceEntryResource;
use App\Modules\Billing\Models\ServiceEntry;
use App\Modules\Billing\Services\RecordServiceEntry;
use App\Modules\Billing\Support\Cents;
use App\Modules\Properties\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ServiceEntryController extends Controller
{
    private const ADJUSTMENTS_SUM_SQL = 'SELECT COALESCE(SUM(service_entry_adjustments.amount), 0)'
        .' FROM service_entry_adjustments WHERE service_entry_adjustments.service_entry_id = service_entries.id';

    /**
     * Review list: entries, totals per currency and completed tasks that still have no entry.
     */
    public function index(ListServiceEntriesRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = $this->filtered(ServiceEntry::query(), $filters);

        // total is the final amount (original plus adjustments); the parts are kept for the review.
        $totals = (clone $query)
            ->selectRaw('currency, COUNT(*) as entries, SUM(amount) as original_total')
            ->selectRaw('SUM(('.self::ADJUSTMENTS_SUM_SQL.')) as adjustments_total')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(function ($row) {
                $original = Cents::from($row->original_total);
                $adjustments = Cents::from($row->adjustments_total);

                return [
                    'currency' => $row->currency,
                    'entries' => (int) $row->entries,
                    'original_total' => Cents::format($original),
                    'adjustments_total' => Cents::format($adjustments),
                    'total' => Cents::format($original + $adjustments),
                ];
            });

        $entries = $query
            ->with(['serviceProvider.user:id,name', 'property:id,name', 'adjustments'])
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        return ServiceEntryResource::collection($entries)
            ->additional(['meta' => [
                'totals' => $totals,
                'tasks_without_entry' => $this->tasksWithoutEntry($filters),
            ]])
            ->response();
    }

    // Review detail: the original entry, every adjustment and the final amount.
    public function show(int $serviceEntry): ServiceEntryResource
    {
        $entry = ServiceEntry::query()
            ->with(['serviceProvider.user:id,name', 'property:id,name', 'adjustments.createdBy:id,name'])
            ->findOrFail($serviceEntry);

        return (new ServiceEntryResource($entry))->withAdjustments();
    }

    // Retry for a completed task that had no amount (e.g. after creating the missing rate).
    public function storeForTask(int $task, RecordServiceEntry $entries): JsonResponse
    {
        Task::query()->findOrFail($task);

        $existed = ServiceEntry::query()->where('task_id', $task)->exists();
        $entry = $entries->forTask($task);

        abort_if(
            $entry === null,
            422,
            'A tarefa não é elegível ou não tem valor definido (valor combinado, tarifa ou valor base).',
        );

        return (new ServiceEntryResource($entry->load(['serviceProvider.user:id,name', 'property:id,name', 'adjustments'])))
            ->response()
            ->setStatusCode($existed ? 200 : 201);
    }

    /**
     * @param  Builder<ServiceEntry>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<ServiceEntry>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->when(isset($filters['month']), function (Builder $query) use ($filters) {
                $start = Carbon::createFromFormat('Y-m-d', $filters['month'].'-01')->startOfDay();

                // Half-open range: service_date may be stored with a time part (e.g. SQLite).
                $query->where('service_date', '>=', $start->toDateString())
                    ->where('service_date', '<', $start->copy()->addMonth()->toDateString());
            })
            ->when(isset($filters['service_provider_id']), fn (Builder $query) => $query->where('service_provider_id', $filters['service_provider_id']))
            ->when(isset($filters['property_id']), fn (Builder $query) => $query->where('property_id', $filters['property_id']));
    }

    /**
     * Completed tasks with a provider and no entry, so the review shows what is missing.
     * The month uses the scheduled start in the property's timezone, like the entries' service_date.
     *
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function tasksWithoutEntry(array $filters): array
    {
        $query = Task::query()
            ->where('status', 'completed')
            ->whereNotNull('service_provider_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('service_entries')
                ->whereColumn('service_entries.task_id', 'tasks.id'))
            ->when(isset($filters['service_provider_id']), fn ($query) => $query->where('service_provider_id', $filters['service_provider_id']))
            ->when(isset($filters['property_id']), fn ($query) => $query->where('property_id', $filters['property_id']))
            ->orderBy('id');

        if (! isset($filters['month'])) {
            return $query->limit(100)->pluck('id')->all();
        }

        // Widen the UTC range by one day on each side (offsets go from -12h to +14h),
        // then keep only the tasks whose local date falls in the month.
        $monthStart = Carbon::createFromFormat('Y-m-d', $filters['month'].'-01', 'UTC')->startOfDay();
        $tasks = $query
            ->where('scheduled_start_at', '>=', $monthStart->copy()->subDay())
            ->where('scheduled_start_at', '<', $monthStart->copy()->addMonth()->addDay())
            ->get(['id', 'property_id', 'scheduled_start_at']);

        $timezones = Property::query()
            ->whereIn('id', $tasks->pluck('property_id')->unique())
            ->pluck('timezone', 'id');

        return $tasks
            ->filter(fn (Task $task) => $task->scheduled_start_at !== null && $task->scheduled_start_at->copy()
                ->setTimezone($timezones->get($task->property_id) ?: Property::DEFAULT_TIMEZONE)
                ->format('Y-m') === $filters['month'])
            ->take(100)
            ->pluck('id')
            ->values()
            ->all();
    }
}
