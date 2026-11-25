<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The checklist version used by a task. Kept in its own table so the tasks table
        // (Tasks module) does not change.
        Schema::create('task_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->unique()->constrained('tasks')->restrictOnDelete();
            $table->foreignId('checklist_template_id')->constrained('checklist_templates')->restrictOnDelete();
            $table->timestamps();
        });

        // One answer per item: done or not, when and by whom (provider or staff intervention).
        Schema::create('task_checklist_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_checklist_id')->constrained('task_checklists')->cascadeOnDelete();
            $table->foreignId('checklist_template_item_id')->constrained('checklist_template_items')->restrictOnDelete();
            $table->boolean('done')->default(false);
            $table->dateTime('done_at')->nullable();
            $table->foreignId('done_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['task_checklist_id', 'checklist_template_item_id'], 'task_checklist_answers_item_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_checklist_answers');
        Schema::dropIfExists('task_checklists');
    }
};
