<?php

namespace App\Modules\Properties\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'phone', 'address', 'postal_code', 'city'])]
class ServiceProvider extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class)->withTimestamps();
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(ServiceProviderAvailability::class);
    }

    public function unavailabilities(): HasMany
    {
        return $this->hasMany(ServiceProviderUnavailability::class);
    }
}
