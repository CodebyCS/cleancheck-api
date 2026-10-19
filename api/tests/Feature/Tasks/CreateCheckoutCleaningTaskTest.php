<?php

namespace Tests\Feature;

use App\Modules\Tasks\Actions\CreateCheckoutCleaningTask;
use App\Models\Task;
use App\Modules\Tasks\Services\CleaningSchedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreateCheckoutCleaningTaskTest extends TestCase
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

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('service_providers', function (Blueprint $table) {
            $table->id();
        });

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

    public function test_it_creates_a_checkout_cleaning_task(): void
    {
        $action = new CreateCheckoutCleaningTask(
            new CleaningSchedule()
        );

        $task = $action->execute(
            1,
            10,
            'checkout_cleaning',
            new \DateTimeImmutable('2026-10-20 10:00:00')
        );

        $this->assertInstanceOf(Task::class, $task);
        $this->assertSame('checkout_cleaning', $task->type);
        $this->assertSame('pending', $task->status);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'booking_id' => 1,
            'property_id' => 10,
        ]);

        $this->assertSame(
            '2026-10-20 11:30:00',
            $task->scheduled_start_at->format('Y-m-d H:i:s')
        );
    }

    public function test_repeating_generation_does_not_duplicate_task(): void
    {
        $action = new CreateCheckoutCleaningTask(
            new CleaningSchedule()
        );

        $date = new \DateTimeImmutable('2026-10-20 10:00:00');

        $first = $action->execute(
            1,
            10,
            'checkout_cleaning',
            $date
        );

        $second = $action->execute(
            1,
            10,
            'checkout_cleaning',
            $date
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Task::count());
    }
}