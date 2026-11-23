<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

#[Fillable(['user_id', 'device_name', 'expires_at', 'revoked_at'])]
class MobileAuthSession extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(MobileRefreshToken::class);
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(
            PersonalAccessToken::class,
            'mobile_auth_session_id',
        );
    }
}
