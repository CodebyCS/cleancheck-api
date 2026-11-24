<?php

namespace App\Modules\Properties\Observers;

use App\Models\Task;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Support\Facades\Schema;

/**
 * Exposes the property's default provider to task creation without changing the Tasks module.
 *
 * A new task without a provider gets the default provider of its property, if that provider
 * is active. Otherwise the task stays unassigned and shows up in the assignment warnings;
 * no other provider is picked automatically.
 *
 * Only new tasks are affected: changing the default provider later never rewrites existing tasks.
 */
class AssignDefaultProviderOnTaskCreating
{
    public function creating(Task $task): void
    {
        if ($task->service_provider_id !== null || $task->property_id === null) {
            return;
        }

        $task->service_provider_id = $this->activeDefaultProviderId((int) $task->property_id);
    }

    public function activeDefaultProviderId(int $propertyId): ?int
    {
        // Some isolated task tests build only the tasks table; without properties there is no default.
        if (! Schema::hasTable('properties')) {
            return null;
        }

        $providerId = Property::query()
            ->whereKey($propertyId)
            ->value('responsible_service_provider_id');

        if ($providerId === null) {
            return null;
        }

        $isActive = ServiceProvider::query()
            ->whereKey($providerId)
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->exists();

        return $isActive ? (int) $providerId : null;
    }
}
