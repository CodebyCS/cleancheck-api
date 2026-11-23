<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_source_id')->constrained()->restrictOnDelete();
            $table->string('external_uid');
            $table->dateTime('source_check_in_at');
            $table->dateTime('source_check_out_at');
            $table->dateTime('overridden_check_in_at')->nullable();
            $table->dateTime('overridden_check_out_at')->nullable();
            $table->unsignedInteger('guest_count')->nullable();
            $table->string('status', 20);
            $table->dateTime('source_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['booking_source_id', 'external_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};