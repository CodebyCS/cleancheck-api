<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_notices', function (Blueprint $table) {
            // Task status when the notice was created (the change may arrive after the start).
            $table->string('task_status', 20)->nullable()->after('task_id');

            // True when the change left a started or completed task untouched.
            $table->boolean('execution_preserved')->default(false)->after('task_status');

            // Next step suggested to the recipient (App\Modules\Reservations\Enums\NoticeAction).
            $table->string('action', 30)->nullable()->after('execution_preserved');
        });
    }

    public function down(): void
    {
        Schema::table('booking_notices', function (Blueprint $table) {
            $table->dropColumn(['task_status', 'execution_preserved', 'action']);
        });
    }
};