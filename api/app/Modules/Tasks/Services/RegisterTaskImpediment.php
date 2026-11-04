<?php

namespace App\Modules\Tasks\Services;

use App\Models\Task;
use App\Models\TaskImpediment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterTaskImpediment
{
    public function execute(Task $task, User $user, array $data): array
    {
        return DB::transaction(function () use ($task, $user, $data): array {
            $task = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $providerId = $user->serviceProvider?->id;
            abort_unless($providerId !== null && (int) $task->service_provider_id === (int) $providerId, 403);

            $record = TaskImpediment::query()->createOrFirst(
                ['task_id' => $task->id, 'user_id' => $user->id, 'idempotency_key' => $data['idempotency_key']],
                ['reason' => $data['reason'], 'description' => $data['description'] ?? null],
            );

            $same = $record->reason === $data['reason']
                && $record->description === ($data['description'] ?? null);

            return [
                'record' => $record,
                'created' => $record->wasRecentlyCreated,
                'conflict' => ! $record->wasRecentlyCreated && ! $same,
            ];
        });
    }
}
