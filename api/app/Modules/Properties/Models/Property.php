<?php

namespace App\Modules\Properties\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name',
    'address',
    'postal_code',
    'city',
    'access_instructions',
    'responsible_service_provider_id',
    'base_cleaning_amount',
    'default_check_in_time',
    'default_check_out_time',
    'timezone',
    'is_active',
])]
class Property extends Model
{
    public const DEFAULT_TIMEZONE = 'Europe/Lisbon';

    /**
     * Default values kept in sync with the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => self::DEFAULT_TIMEZONE,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'base_cleaning_amount' => 'decimal:2',
        ];
    }

    public function responsibleServiceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class, 'responsible_service_provider_id');
    }
}
