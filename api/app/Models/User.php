<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

//#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'role_id',
        'name',
        'email',
        'password',
        'phone',
        'address',
        'profile_photo_url',
        'is_active',
    ];
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

        public function role(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function serviceProvider(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ServiceProvider::class);
    }

    // Named appNotifications to avoid clashing with Notifiable::notifications().
    public function appNotifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Notification::class, 'recipient_user_id');
    }
}
