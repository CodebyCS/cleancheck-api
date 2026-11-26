<?php

namespace App\Modules\Checklists\Observers;

use App\Models\Task;
use App\Modules\Checklists\Services\TaskChecklists;
use Illuminate\Support\Facades\Schema;

class AttachChecklistOnTaskCreated
{
    public function __construct(private readonly TaskChecklists $checklists) {}

    // New tasks keep the checklist version in force when they were created.
    public function created(Task $task): void
    {
        // Lets the Tasks module run on its own (e.g. tests without the Checklists tables).
        if (! Schema::hasTable('task_checklists')) {
            return;
        }

        $this->checklists->attach($task);
    }
}
