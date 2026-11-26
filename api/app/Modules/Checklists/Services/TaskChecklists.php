<?php

namespace App\Modules\Checklists\Services;

use App\Models\Task;
use App\Modules\Checklists\Models\ChecklistTemplate;
use App\Modules\Checklists\Models\TaskChecklist;

class TaskChecklists
{
    /** Only open tasks can still receive a checklist after they were created. */
    private const OPEN_STATUSES = ['pending', 'in_progress'];

    /**
     * Links the task to the checklist version currently in force for its type.
     * Returns null when no checklist exists yet for that type.
     */
    public function attach(Task $task): ?TaskChecklist
    {
        $existing = TaskChecklist::query()->where('task_id', $task->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $template = ChecklistTemplate::query()->latestFor($task->type)->first();

        if ($template === null) {
            return null;
        }

        return TaskChecklist::query()->firstOrCreate(
            ['task_id' => $task->id],
            ['checklist_template_id' => $template->id],
        );
    }

    /**
     * The task's checklist with its items and answers. A task created before any checklist existed
     * gets the current version on first read, but only while it is still open.
     */
    public function forTask(Task $task): ?TaskChecklist
    {
        $checklist = TaskChecklist::query()->where('task_id', $task->id)->first();

        if ($checklist === null && in_array($task->status, self::OPEN_STATUSES, true)) {
            $checklist = $this->attach($task);
        }

        return $checklist?->load(['template.items', 'answers.doneBy']);
    }
}
