<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['service_provider_id']);
            $table->foreign('service_provider_id')->references('id')->on('service_providers')->restrictOnDelete();
        });

        Schema::create('task_assignment_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('previous_service_provider_id')->nullable()->constrained('service_providers')->restrictOnDelete();
            $table->foreignId('new_service_provider_id')->constrained('service_providers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignment_history');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['service_provider_id']);
            $table->foreign('service_provider_id')->references('id')->on('service_providers')->nullOnDelete();
        });
    }
};
