<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Booking;
use App\Models\Property;
use App\Models\ServiceProvider;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'booking_id',
        'property_id',
        'service_provider_id',
        'type',
        'status',
        'scheduled_start_at',
        'scheduled_end_at',
        'started_at',
        'completed_at',
        'agreed_amount',
        'notes',
    ];

    // Cast for date treatament from the database

    protected  $casts = [
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'agreed_amount' => 'decimal:2',
    ];

    public function booking(){
        return $this->belongsTo(Booking::class);
    }

    public function property(){
        return $this->belongsTo(Property::class);
    }

    public function serviceProvider(){
        return $this->belongsTo(ServiceProvider::class);
    }

}
