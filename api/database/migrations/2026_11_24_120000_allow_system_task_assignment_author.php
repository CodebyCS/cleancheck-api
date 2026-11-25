<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_assignment_history', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep system-authored history intact; rollback is intentionally blocked once it exists.
        if (DB::table('task_assignment_history')->whereNull('user_id')->exists()) {
            throw new RuntimeException('Não é possível remover autores de sistema sem perder o histórico de atribuições.');
        }

        Schema::table('task_assignment_history', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
