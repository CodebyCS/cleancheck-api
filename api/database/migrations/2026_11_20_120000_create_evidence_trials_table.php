<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_trials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('kind', 16);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('original_name');
            $table->string('device', 120)->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['task_id', 'received_at']);
            $table->index(['user_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_trials');
    }
};
