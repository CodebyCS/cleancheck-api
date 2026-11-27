<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_executions', function (Blueprint $table): void {
            $table->foreignId('correction_of_execution_id')
                ->nullable()
                ->unique()
                ->constrained('task_executions')
                ->restrictOnDelete();
        });

        Schema::create('task_execution_rejections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_execution_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('rejected_by_user_id')->constrained('users')->restrictOnDelete();
            $table->longText('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_execution_rejections');

        Schema::table('task_executions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('correction_of_execution_id');
        });
    }
};
