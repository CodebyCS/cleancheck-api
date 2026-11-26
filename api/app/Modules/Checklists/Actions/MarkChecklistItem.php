<?php

namespace App\Modules\Checklists\Actions;

use App\Models\Task;
use App\Models\User;
use App\Modules\Checklists\Models\TaskChecklist;
use App\Modules\Checklists\Models\TaskChecklistAnswer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkChecklistItem
{
    /**
     * Marks one item of the task's checklist as done or not done. Items are answered one at a time;
     * there is no "mark all". Completing the task is not blocked by the checklist.
     */
    public function execute(Task $task, int $itemId, bool $done, User $user): TaskChecklist
    {
        return DB::transaction(function () use ($task, $itemId, $done, $user): TaskChecklist {
            $task = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($task->status !== 'in_progress') {
                throw ValidationException::withMessages([
                    'status' => 'A checklist só pode ser preenchida com a tarefa em curso.',
                ]);
            }

            $checklist = TaskChecklist::query()
                ->with('template.items')
                ->where('task_id', $task->id)
                ->first();

            // The item must belong to the version linked to this task.
            abort_if(
                $checklist === null || ! $checklist->template->items->contains('id', $itemId),
                404,
                'Item de checklist não encontrado nesta tarefa.',
            );

            TaskChecklistAnswer::query()->updateOrCreate(
                [
                    'task_checklist_id' => $checklist->id,
                    'checklist_template_item_id' => $itemId,
                ],
                [
                    'done' => $done,
                    'done_at' => $done ? now() : null,
                    'done_by_user_id' => $done ? $user->id : null,
                ],
            );

            return $checklist->load(['template.items', 'answers.doneBy']);
        });
    }
}
