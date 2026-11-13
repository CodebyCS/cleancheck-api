<?php

namespace App\Modules\Tasks\Infrastructure;

use App\Models\Task;
use App\Modules\Tasks\Contracts\ProviderOpenTasks;

class EloquentProviderOpenTasks implements ProviderOpenTasks
{
    public function count(int $serviceProviderId): int
    {
        return Task::query()
            ->where('service_provider_id', $serviceProviderId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();
    }
}