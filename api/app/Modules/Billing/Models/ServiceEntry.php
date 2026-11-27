<?php

namespace App\Modules\Billing\Models;

use App\Models\Task;
use App\Modules\Billing\Support\Cents;
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

    // Sum of the adjustments, using the loaded relation when available.
    public function adjustmentsTotal(): string
    {
        $cents = $this->relationLoaded('adjustments')
            ? $this->adjustments->sum(fn (ServiceEntryAdjustment $adjustment) => Cents::from($adjustment->amount))
            : Cents::from($this->adjustments()->sum('amount'));

        return Cents::format($cents);
    }

    // Original amount plus adjustments: the value that counts in the review.
    public function finalAmount(): string
    {
        return Cents::format(Cents::from($this->amount) + Cents::from($this->adjustmentsTotal()));
    }
}
