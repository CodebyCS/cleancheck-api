<?php

namespace App\Modules\Checklists\Http\Resources;

use App\Modules\Checklists\Models\ChecklistTemplateItem;
use App\Modules\Checklists\Models\TaskChecklistAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskChecklistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $answers = $this->answers->keyBy('checklist_template_item_id');

        $items = $this->template->items->map(function (ChecklistTemplateItem $item) use ($answers): array {
            /** @var TaskChecklistAnswer|null $answer */
            $answer = $answers->get($item->id);
            $done = (bool) $answer?->done;

            return [
                'id' => $item->id,
                'position' => $item->position,
                'label' => $item->label,
                'required' => $item->is_required,
                'done' => $done,
                'done_at' => $done ? $answer->done_at?->toIso8601String() : null,
                'done_by' => $done && $answer->doneBy !== null
                    ? ['id' => $answer->doneBy->id, 'name' => $answer->doneBy->name]
                    : null,
            ];
        })->values();

        $required = $items->where('required', true);
        $requiredDone = $required->where('done', true)->count();

        return [
            'task_id' => $this->task_id,
            'checklist_template_id' => $this->checklist_template_id,
            'name' => $this->template->name,
            'version' => $this->template->version,
            'required_total' => $required->count(),
            'required_done' => $requiredDone,
            'completed' => $requiredDone === $required->count(),
            'items' => $items,
        ];
    }
}
