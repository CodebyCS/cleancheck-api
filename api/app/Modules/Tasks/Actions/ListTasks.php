<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ListTasks
{
    private const DEFAULT_PER_PAGE = 15;

    public function forStaff(array $filters): LengthAwarePaginator
    {
        $query = Task::query()->with(['property', 'serviceProvider.user', 'completedBy']);

        if (($filters['deleted'] ?? null) === 'only') {
            $query->onlyTrashed();
        }

        return $this->filtered($query, $filters);
    }

    /** Service provider: only the tasks assigned to them. */
    public function forProvider(int $serviceProviderId, array $filters): LengthAwarePaginator
    {
        $query = Task::query()
            ->with('property')
            ->where('service_provider_id', $serviceProviderId);

        return $this->filtered($query, $filters);
    }

    /** Note to the admin: Includes deleted items, so you can view them before restoring. */
    public function findForStaff(int $taskId): Task
    {
        return Task::withTrashed()
            ->with(['property', 'serviceProvider.user', 'completedBy'])
            ->findOrFail($taskId);
    }

    /** Note to the provider: only if it's theirs. If not, return a 404 response. */
    public function findForProvider(int $taskId, int $serviceProviderId): Task
    {
        return Task::query()
            ->with('property')
            ->where('service_provider_id', $serviceProviderId)
            ->findOrFail($taskId);
    }

    private function filtered(Builder $query, array $filters): LengthAwarePaginator
    {
        $query
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('scheduled_start_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('scheduled_start_at', '<=', Carbon::parse($to)->endOfDay()))
            ->when($filters['property_id'] ?? null, fn ($q, $id) => $q->where('property_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->orderBy('scheduled_start_at')
            ->orderBy('id');

        return $query
            ->paginate((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE))
            ->withQueryString();
    }
}