<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Modules\Billing\Http\Requests\ListServiceEntriesRequest;
use App\Modules\Billing\Http\Resources\ServiceEntryResource;
use App\Modules\Billing\Models\ServiceEntry;
use App\Modules\Billing\Services\RecordServiceEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ServiceEntryController extends Controller
{
    /**
     * Review list: entries, totals per currency and completed tasks that still have no entry.
     */
    public function index(ListServiceEntriesRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = $this->filtered(ServiceEntry::query(), $filters);

        $totals = (clone $query)
            ->selectRaw('currency, COUNT(*) as entries, SUM(amount) as total')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row) => [
                'currency' => $row->currency,
                'entries' => (int) $row->entries,
                'total' => number_format((float) $row->total, 2, '.', ''),
            ]);

        $entries = $query
            ->with(['serviceProvider.user:id,name', 'property:id,name'])
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

        return (new ServiceEntryResource($entry->load(['serviceProvider.user:id,name', 'property:id,name'])))
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

                $query->whereBetween('service_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()]);
            })
            ->when(isset($filters['service_provider_id']), fn (Builder $query) => $query->where('service_provider_id', $filters['service_provider_id']))
            ->when(isset($filters['property_id']), fn (Builder $query) => $query->where('property_id', $filters['property_id']));
    }

    /**
     * Completed tasks with a provider and no entry, so the review shows what is missing.
     * The month filter uses the scheduled start in UTC, which can differ by one day at the edges.
     *
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function tasksWithoutEntry(array $filters): array
    {
        return Task::query()
            ->where('status', 'completed')
            ->whereNotNull('service_provider_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('service_entries')
                ->whereColumn('service_entries.task_id', 'tasks.id'))
            ->when(isset($filters['month']), function ($query) use ($filters) {
                $start = Carbon::createFromFormat('Y-m-d', $filters['month'].'-01', 'UTC')->startOfDay();

                $query->where('scheduled_start_at', '>=', $start)
                    ->where('scheduled_start_at', '<', $start->copy()->addMonth());
            })
            ->when(isset($filters['service_provider_id']), fn ($query) => $query->where('service_provider_id', $filters['service_provider_id']))
            ->when(isset($filters['property_id']), fn ($query) => $query->where('property_id', $filters['property_id']))
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->all();
    }
}
