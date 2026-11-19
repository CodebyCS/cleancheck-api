<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_notices', function (Blueprint $table) {
            // Task notices may come from a manual task, with no booking and no stay dates.
            $table->foreignId('booking_id')->nullable()->change();
            $table->date('check_in_on')->nullable()->change();
            $table->date('check_out_on')->nullable()->change();

            // Schedule shown in task notices (what the provider must know).
            $table->dateTime('scheduled_start_at')->nullable()->after('check_out_on');
            $table->dateTime('scheduled_end_at')->nullable()->after('scheduled_start_at');

            // Idempotency key built from the origin event, e.g. "task_assigned:42".
            $table->string('dedup_key', 120)->nullable()->after('type');
            $table->unique(['user_id', 'dedup_key'], 'booking_notices_dedup_unique');
        });
    }

    public function down(): void
    {
        // Task notices cannot exist without the new columns.
        DB::table('booking_notices')->whereNull('booking_id')->delete();

        Schema::table('booking_notices', function (Blueprint $table) {
            $table->dropUnique('booking_notices_dedup_unique');
            $table->dropColumn(['dedup_key', 'scheduled_start_at', 'scheduled_end_at']);
            $table->foreignId('booking_id')->nullable(false)->change();
            $table->date('check_in_on')->nullable(false)->change();
            $table->date('check_out_on')->nullable(false)->change();
        });
    }
};