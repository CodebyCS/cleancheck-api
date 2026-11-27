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
        // Corrections to a service entry. The original entry is never changed or deleted:
        // its final amount is the original amount plus the sum of its adjustments.
        Schema::create('service_entry_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_entry_id')->constrained('service_entries')->restrictOnDelete();
            // Signed difference in the entry's currency (negative to reduce, positive to increase).
            $table->decimal('amount', 10, 2);
            $table->string('reason', 500);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_entry_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_entry_adjustments');
    }
};
