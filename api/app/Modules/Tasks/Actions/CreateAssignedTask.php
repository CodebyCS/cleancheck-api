<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use App\Modules\Tasks\Events\TaskAssigned;
use App\Modules\Tasks\Validators\TaskGenerationValidator;
use Illuminate\Support\Facades\DB;

class CreateAssignedTask
{
    public function __construct(private TaskGenerationValidator $validator) {}

    /** A null author identifies an automatic system assignment. */
    public function execute(array $attributes, ?int $userId = null): Task
    {
        return DB::transaction(function () use ($attributes, $userId): Task {
            $providerId = $this->validator->defaultProviderId((int) $attributes['property_id']);
            $attributes['service_provider_id'] = $providerId;

            $task = Task::create($attributes);
            $history = $task->assignmentHistory()->create([
                'previous_service_provider_id' => null,
                'new_service_provider_id' => $providerId,
                'user_id' => $userId,
                'created_at' => now(),
            ]);

            TaskAssigned::dispatch($task->id, null, $providerId, $history->id);

            return $task;
        });
    }
}
