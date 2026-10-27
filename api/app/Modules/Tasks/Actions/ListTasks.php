<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ListTasks
{
    private const DEFAULT_PER_PAGE = 15;

    /** Adm/supervisor: todas as tasks (e, com deleted=only, só as apagadas). */
    public function forStaff(array $filters): LengthAwarePaginator
    {
        // ADAPTAR: 'serviceProvider.user' depende da relação do Pedro (nome do prestador)
        $query = Task::query()->with(['property', 'serviceProvider.user']);

        if (($filters['deleted'] ?? null) === 'only') {
            $query->onlyTrashed();
        }

        return $this->filtered($query, $filters);
    }

    /** Prestador: só as tasks que lhe estão atribuídas. */
    public function forProvider(int $serviceProviderId, array $filters): LengthAwarePaginator
    {
        $query = Task::query()
            ->with('property')
            ->where('service_provider_id', $serviceProviderId);

        return $this->filtered($query, $filters);
    }

    /** Detalhe para o adm: inclui apagadas, para poder ver antes de restaurar. */
    public function findForStaff(int $taskId): Task
    {
        return Task::withTrashed()
            ->with(['property', 'serviceProvider.user'])
            ->findOrFail($taskId);
    }

    /** Detalhe para o prestador: só se for dele. Se não for, responde 404. */
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