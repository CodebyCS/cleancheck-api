<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskCancellationHistory extends Model
{
    protected $table = 'task_cancellation_history';

    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'source',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}