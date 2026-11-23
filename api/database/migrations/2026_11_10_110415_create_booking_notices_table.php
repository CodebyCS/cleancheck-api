<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('type', 30);
            $table->date('check_in_on');
            $table->date('check_out_on');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // One notice per person, booking, kind of change and resulting dates.
            $table->unique(
                ['user_id', 'booking_id', 'type', 'check_in_on', 'check_out_on'],
                'booking_notices_change_unique',
            );
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_notices');
    }
};