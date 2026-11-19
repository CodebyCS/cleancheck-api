<?php

namespace App\Modules\Properties\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_provider_id', 'weekday', 'start_time', 'end_time'])]
class ServiceProviderAvailability extends Model
{
    public const MONDAY = 1;

    public const SUNDAY = 7;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }
}
