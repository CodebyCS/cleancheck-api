<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'mobile_auth_session_id',
    'token_hash',
    'expires_at',
    'used_at',
    'revoked_at',
])]
class MobileRefreshToken extends Model
{
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(
            MobileAuthSession::class,
            'mobile_auth_session_id',
        );
    }
}
