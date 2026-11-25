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
        // One entry per eligible service. Amount and currency are a snapshot taken when the
        // entry is created; later changes to rates, tasks or properties do not affect it.
        Schema::create('service_entries', function (Blueprint $table) {
            $table->id();
            // Unique: repeating the operation can never count the same task twice.
            $table->foreignId('task_id')->unique()->constrained('tasks')->restrictOnDelete();
            $table->foreignId('service_provider_id')->constrained('service_providers')->restrictOnDelete();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('task_type', 40);
            // Property local date of the service, used for the monthly totals.
            $table->date('service_date');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            // Where the amount came from: task_agreed_amount, rate or property_base.
            $table->string('amount_source', 30);
            $table->foreignId('rate_id')->nullable()->constrained('rates')->restrictOnDelete();
            $table->timestamps();

            $table->index(['service_provider_id', 'service_date']);
            $table->index(['property_id', 'service_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_entries');
    }
};
