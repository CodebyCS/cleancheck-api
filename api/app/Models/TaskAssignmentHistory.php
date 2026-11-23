<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskAssignmentHistory extends Model
{
    protected $table = 'task_assignment_history';

    public $timestamps = false;

    protected $fillable = [
        'task_id', 'previous_service_provider_id', 'new_service_provider_id', 'user_id', 'created_at',
    ];
}
