<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Properties\Models\Property;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['property_id', 'channel', 'type', 'url', 'file_path', 'is_active'])]
final class BookingSource extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

        public function latestSyncRun(): HasOne
    {
        return $this->hasOne(SyncRun::class)->latestOfMany();
    }

    public function latestSuccessfulSyncRun(): HasOne
    {
        return $this->hasOne(SyncRun::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('status', 'success'),
        );
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

        public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}