<?php

namespace App\Modules\Checklists\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['task_type', 'version', 'name', 'created_by_user_id'])]
class ChecklistTemplate extends Model
{
    /** Task types that can have a checklist. */
    public const TASK_TYPES = ['checkout_cleaning', 'checkin_inspection'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class)->orderBy('position');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Latest version of the checklist for a task type.
     *
     * @param  Builder<ChecklistTemplate>  $query
     */
    public function scopeLatestFor(Builder $query, string $taskType): void
    {
        $query->where('task_type', $taskType)->orderByDesc('version');
    }
}
