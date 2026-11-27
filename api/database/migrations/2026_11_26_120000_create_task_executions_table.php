<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->json('responses');
            $table->text('observations')->nullable();
            $table->string('send_state', 20);
            $table->timestamp('confirmed_at');
            $table->timestamps();
            $table->unique(['task_id', 'user_id', 'idempotency_key'], 'task_execution_request_unique');
        });

        Schema::create('task_execution_evidence', function (Blueprint $table): void {
            $table->foreignId('task_execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_evidence_id')->constrained('task_evidences')->restrictOnDelete();
            $table->primary(['task_execution_id', 'task_evidence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_execution_evidence');
        Schema::dropIfExists('task_executions');
    }
};
