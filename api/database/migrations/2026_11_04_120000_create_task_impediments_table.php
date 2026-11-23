<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_impediments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 120);
            $table->text('description')->nullable();
            $table->uuid('idempotency_key');
            $table->timestamps();
            $table->unique(['task_id', 'user_id', 'idempotency_key'], 'task_impediments_idempotency_unique');
        });
    }

    public function down(): void { Schema::dropIfExists('task_impediments'); }
};
