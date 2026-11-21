<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Properties\Models\Property;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['property_id', 'task_type', 'amount', 'currency', 'valid_from', 'created_by_user_id'])]
class Rate extends Model
{
    public const DEFAULT_CURRENCY = 'EUR';

    /**
     * Default values kept in sync with the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => self::DEFAULT_CURRENCY,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'valid_from' => 'date',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
