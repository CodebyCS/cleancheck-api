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
        // Rates are append-only: a new row replaces the previous one from valid_from onwards,
        // so changing a rate never rewrites past service entries.
        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            // Null means a general rate, used when the property has no rate of its own.
            $table->foreignId('property_id')->nullable()->constrained('properties')->restrictOnDelete();
            $table->string('task_type', 40);
            $table->decimal('amount', 10, 2);
            // ISO 4217 code, e.g. EUR or BRL.
            $table->char('currency', 3)->default('EUR');
            // First service day (property local date) on which the rate applies.
            $table->date('valid_from');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['task_type', 'property_id', 'valid_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rates');
    }
};
