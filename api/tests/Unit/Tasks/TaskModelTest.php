<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);

        \DB::purge('sqlite');
        \DB::setDefaultConnection('sqlite');

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('service_provider_id')->nullable();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->dateTime('scheduled_start_at');
            $table->dateTime('scheduled_end_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->decimal('agreed_amount', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['booking_id', 'type']);
        });
    }

    public function test_task_can_be_created_and_casts_dates(): void
    {
        $task = Task::create([
            'booking_id' => 1,
            'property_id' => 10,
            'type' => 'checkout_cleaning',
            'status' => 'pending',
            'scheduled_start_at' => '2026-10-20 11:30:00',
            'scheduled_end_at' => null,
        ]);

        $this->assertInstanceOf(Task::class, $task);
        $this->assertInstanceOf(
            \Illuminate\Support\Carbon::class,
            $task->scheduled_start_at
        );
        $this->assertNull($task->scheduled_end_at);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'booking_id' => 1,
            'property_id' => 10,
            'type' => 'checkout_cleaning',
        ]);
    }

    public function test_task_can_be_soft_deleted(): void
    {
        $task = Task::create([
            'booking_id' => 1,
            'property_id' => 10,
            'type' => 'checkout_cleaning',
            'status' => 'pending',
            'scheduled_start_at' => '2026-10-20 11:30:00',
        ]);

        $task->delete();

        $this->assertSoftDeleted('tasks', [
            'id' => $task->id,
        ]);

        $this->assertNull(Task::find($task->id));
        $this->assertNotNull(Task::withTrashed()->find($task->id));
    }
}