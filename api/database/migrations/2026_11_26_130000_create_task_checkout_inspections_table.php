<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checkout_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->json('responses');
            $table->text('observations')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['task_id', 'idempotency_key'], 'checkout_inspection_key_unique');
        });

        Schema::create('task_checkout_inspection_evidence', function (Blueprint $table): void {
            $table->foreignId('inspection_id')->constrained('task_checkout_inspections')->cascadeOnDelete();
            $table->foreignId('task_evidence_id')->constrained('task_evidences')->restrictOnDelete();
            $table->primary(['inspection_id', 'task_evidence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checkout_inspection_evidence');
        Schema::dropIfExists('task_checkout_inspections');
    }
};
