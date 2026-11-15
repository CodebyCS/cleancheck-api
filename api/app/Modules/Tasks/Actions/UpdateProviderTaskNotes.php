<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProviderTaskNotes
{
    public function execute(
        int $taskId,
        int $serviceProviderId,
        ?string $providerNotes,
    ): Task {
        return DB::transaction(function () use ($taskId, $serviceProviderId, $providerNotes): Task {
            $task = Task::query()
                ->whereKey($taskId)
                ->where('service_provider_id', $serviceProviderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($task->status !== 'in_progress') {
                throw ValidationException::withMessages([
                    'status' => 'As notas do prestador só podem ser alteradas enquanto a tarefa está em andamento.',
                ]);
            }

            if ($task->provider_notes === $providerNotes) {
                return $task;
            }

            $task->update([
                'provider_notes' => $providerNotes,
            ]);

            return $task->refresh();
        });
    }
}