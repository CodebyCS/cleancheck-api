<?php

declare(strict_types=1);

namespace App\Modules\Alerts\Models;

use App\Models\Task;
use App\Models\User;
use App\Modules\Alerts\Enums\AlertAction;
use App\Modules\Alerts\Enums\AlertPriority;
use App\Modules\Alerts\Enums\AlertResolution;
use App\Modules\Alerts\Enums\AlertType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TaskAlert extends Model
{
    protected $fillable = [
        'task_id',
        'type',
        'priority',
        'reason',
        'action',
        'active_key',
        'detected_at',
        'resolved_at',
        'resolution',
        'resolved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'priority' => AlertPriority::class,
            'action' => AlertAction::class,
            'resolution' => AlertResolution::class,
            'detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    // A cancelled task is soft deleted, but its alerts stay in the history.
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public static function activeKey(int $taskId, AlertType $type): string
    {
        return "task:{$taskId}:{$type->value}";
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
    
}