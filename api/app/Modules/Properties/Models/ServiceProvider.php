<?php

namespace App\Modules\Properties\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'phone'])]
class ServiceProvider extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
