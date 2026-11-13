<?php

namespace Database\Seeders;

use App\Models\Task;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException(
                'Task demo data can only be seeded in local or testing environments.'
            );
        }

        if (Task::withTrashed()->exists()) {
            return;
        }

        $providerIds = ServiceProvider::query()
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->pluck('id');

        $firstProviderId = $providerIds->get(0);
        $secondProviderId = $providerIds->get(1) ?? $firstProviderId;

        Task::factory()->create([
            'service_provider_id' => $firstProviderId,
        ]);

        Task::factory()->inProgress()->create([
            'service_provider_id' => $secondProviderId,
        ]);

        Task::factory()->checkinInspection()->create([
            'service_provider_id' => $firstProviderId,
        ]);
    }
}