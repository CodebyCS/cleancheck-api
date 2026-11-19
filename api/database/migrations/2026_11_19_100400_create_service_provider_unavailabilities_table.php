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
        // Exceptions to the weekly hours: holidays, sick leave or a few hours off.
        Schema::create('service_provider_unavailabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_provider_id')->constrained('service_providers')->restrictOnDelete();
            // Stored in UTC, like the rest of the API. A full day is 00:00 to 00:00 of the next day.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason', 120)->nullable();
            $table->timestamps();

            $table->index(['service_provider_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_provider_unavailabilities');
    }
};
