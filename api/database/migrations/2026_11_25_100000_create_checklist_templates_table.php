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
        // One generic checklist per task type. Editing it creates a new version; versions are
        // never changed, so a task keeps the exact items it was executed with.
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('task_type', 40);
            $table->unsignedInteger('version');
            $table->string('name', 120);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['task_type', 'version']);
        });

        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained('checklist_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('label', 200);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['checklist_template_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklist_template_items');
        Schema::dropIfExists('checklist_templates');
    }
};
