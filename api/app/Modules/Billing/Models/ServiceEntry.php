<?php

namespace App\Modules\Billing\Models;

use App\Models\Task;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'task_id',
    'service_provider_id',
    'property_id',
    'task_type',
    'service_date',
    'amount',
    'currency',
    'amount_source',
    'rate_id',
])]
class ServiceEntry extends Model
{
    public const SOURCE_TASK_AGREED_AMOUNT = 'task_agreed_amount';

    public const SOURCE_RATE = 'rate';

    public const SOURCE_PROPERTY_BASE = 'property_base';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'service_date' => 'date',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class);
    }

    // Corrections recorded after the entry was created, oldest first.
    public function adjustments(): HasMany
    {
        return $this->hasMany(ServiceEntryAdjustment::class)->orderBy('created_at')->orderBy('id');
    }
}
