<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidence_trials', function (Blueprint $table): void {
            $table->dropForeign(['task_id']);
            $table->unsignedBigInteger('task_id')->nullable()->change();
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('evidence_trials')->whereNull('task_id')->delete();

        Schema::table('evidence_trials', function (Blueprint $table): void {
            $table->dropForeign(['task_id']);
            $table->unsignedBigInteger('task_id')->nullable(false)->change();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });
    }
};