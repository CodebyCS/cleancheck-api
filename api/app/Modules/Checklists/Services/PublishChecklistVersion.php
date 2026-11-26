<?php

namespace App\Modules\Checklists\Services;

use App\Models\User;
use App\Modules\Checklists\Models\ChecklistTemplate;
use Illuminate\Support\Facades\DB;

class PublishChecklistVersion
{
    /**
     * Creates the next version of a task type's checklist. Existing versions are never changed,
     * so tasks that already use one keep their items.
     *
     * @param  array<int, array{label: string, required?: bool}>  $items
     */
    public function handle(string $taskType, string $name, array $items, User $author): ChecklistTemplate
    {
        return DB::transaction(function () use ($taskType, $name, $items, $author): ChecklistTemplate {
            $lastVersion = ChecklistTemplate::query()
                ->where('task_type', $taskType)
                ->lockForUpdate()
                ->max('version');

            $template = ChecklistTemplate::create([
                'task_type' => $taskType,
                'version' => ((int) $lastVersion) + 1,
                'name' => $name,
                'created_by_user_id' => $author->id,
            ]);

            foreach (array_values($items) as $index => $item) {
                $template->items()->create([
                    'position' => $index + 1,
                    'label' => $item['label'],
                    'is_required' => $item['required'] ?? true,
                ]);
            }

            return $template->load('items');
        });
    }
}
