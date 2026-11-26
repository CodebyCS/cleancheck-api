<?php

namespace App\Modules\Checklists\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_checklist_id', 'checklist_template_item_id', 'done', 'done_at', 'done_by_user_id'])]
class TaskChecklistAnswer extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'done' => 'boolean',
            'done_at' => 'datetime',
        ];
    }

    public function taskChecklist(): BelongsTo
    {
        return $this->belongsTo(TaskChecklist::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplateItem::class, 'checklist_template_item_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by_user_id');
    }
}
