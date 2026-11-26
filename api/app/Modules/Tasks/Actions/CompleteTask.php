<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteTask
{
    /**
     * A service provider can only complete tasks assigned to their profile.
     */
    public function forProvider(
        int $taskId,
        int $serviceProviderId,
        int $userId,
    ): Task {
        return DB::transaction(function () use ($taskId, $serviceProviderId, $userId): Task {
            $task = Task::query()
                ->whereKey($taskId)
                ->where('service_provider_id', $serviceProviderId)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->completeTask($task, $userId);
        });
    }

    /**
     * The calling route must be protected for admin/supervisor.
     */
    public function forStaff(int $taskId, int $userId): Task
    {
        return DB::transaction(function () use ($taskId, $userId): Task {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->completeTask($task, $userId, true);
        });
    }

    /**
     * Completes a set of tasks for staff in one transaction.
     *
     * If any task cannot be completed, the whole batch is rolled back.
     *
     * @param array<int> $taskIds
     * @return \Illuminate\Support\Collection<int, Task>
     */
    public function forStaffMany(array $taskIds, int $userId, bool $acceptMissingEvidence = false,)
    {
        return DB::transaction(function () use ($taskIds, $userId, $acceptMissingEvidence) {
            $tasks = Task::query()
                ->with(['completedBy.role'])
                ->withExists(['evidences as has_evidence'])
                ->whereIn('id', $taskIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($tasks->count() !== count(array_unique($taskIds))) {
                throw ValidationException::withMessages([
                    'task_ids' => 'Uma ou mais tarefas não foram encontradas.',
                ]);
            }

            return $tasks->map(function (Task $task) use ($userId, $acceptMissingEvidence): Task {
                $isMissingEvidence = $task->status === 'completed'
                    && $task->completedBy?->hasRole('service_provider') === true
                    && !(bool) $task->has_evidence
                    && $task->evidence_accepted_at === null;

                if ($isMissingEvidence && !$acceptMissingEvidence) {
                    throw ValidationException::withMessages([
                        'accept_missing_evidence' => "A tarefa #{$task->id} está concluída, mas falta evidência. É necessário confirmar a aceitação.",
                    ]);
                }

                if ($isMissingEvidence && $task->evidence_accepted_at === null) {
                    $task->update([
                        'evidence_accepted_at' => now(),
                        'evidence_accepted_by_user_id' => $userId,
                    ]);

                    return $task->refresh();
                }

                return $this->completeTask($task, $userId, true);
            });
        });
    }

    private function completeTask(Task $task, int $userId, bool $allowPending = false,): Task
    {
        // A repeat does not change the person who completed the task or alter the `completed_at` field.
        if ($task->status === 'completed') {
            return $task;
        }

        if (
            $task->status !== 'in_progress'
            && !($allowPending && $task->status === 'pending')
        ) {
            throw ValidationException::withMessages([
                'status' => 'Apenas tarefas pendentes ou em andamento podem ser concluídas pela equipa.',
            ]);
        }

        // The conclusion does not require media.
        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by_user_id' => $userId,
        ]);

        return $task->refresh();
    }
}