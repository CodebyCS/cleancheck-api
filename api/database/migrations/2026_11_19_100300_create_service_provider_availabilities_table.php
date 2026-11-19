<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Weekly working hours of each service provider. A day can have more than one interval.
        Schema::create('service_provider_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_provider_id')->constrained('service_providers')->restrictOnDelete();
            // ISO-8601 weekday: 1 = Monday ... 7 = Sunday.
            $table->unsignedTinyInteger('weekday');
            // Local times, read in the timezone of the property being assessed.
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['service_provider_id', 'weekday']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_provider_availabilities');
    }
};
