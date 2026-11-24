<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('priority', 10);
            $table->string('reason', 255);
            $table->string('action', 40);
            // Filled while the alert is active and cleared when it is resolved:
            // at most one active alert per task and type, even with two detectors running.
            $table->string('active_key', 60)->nullable()->unique();
            $table->dateTime('detected_at');
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution', 30)->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['resolved_at', 'priority']);
            $table->index(['task_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_alerts');
    }
};