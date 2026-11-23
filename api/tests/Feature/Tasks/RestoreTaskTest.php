<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use Illuminate\Validation\ValidationException;
use App\Modules\Tasks\Actions\RestoreTask;
use App\Modules\Tasks\Contracts\BookingDataProvider;
use App\Modules\Tasks\Services\CleaningSchedule;
use App\Modules\Tasks\Support\BookingSnapshot;
use App\Modules\Tasks\Validators\TaskGenerationValidator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RestoreTaskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('tasks', function (Blueprint $table): void {
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
            $table->unsignedBigInteger('completed_by_user_id')->nullable();
            $table->decimal('agreed_amount', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['booking_id', 'type']);
        });
    }

    public function test_restoring_a_cancelled_task_resets_execution_timestamps(): void
    {
        $checkIn = Carbon::now()->addDays(12)->setTime(15, 0);
        $checkOut = Carbon::now()->addDays(10)->setTime(10, 0);
        $snapshot = new BookingSnapshot(
            bookingId: 1,
            propertyId: 10,
            checkIn: $checkIn,
            checkOut: $checkOut,
            nextCheckIn: null,
            propertyActive: true,
        );

        $task = Task::create([
            'booking_id' => 1,
            'property_id' => 10,
            'type' => 'checkout_cleaning',
            'status' => 'cancelled',
            'scheduled_start_at' => Carbon::now()->subDays(2),
            'scheduled_end_at' => Carbon::now()->subDay(),
            'started_at' => Carbon::now()->subDays(2),
            'completed_at' => Carbon::now()->subDays(2)->addHour(),
            'completed_by_user_id' => 123,
        ]);
        $task->delete();

        $bookings = $this->createMock(BookingDataProvider::class);
        $bookings->method('find')
            ->with(1)
            ->willReturn($snapshot);

        $validator = $this->createMock(TaskGenerationValidator::class);
        $validator->expects($this->once())
            ->method('validateCheckoutCleaning')
            ->with($snapshot);

        $action = new RestoreTask(
            $bookings,
            new CleaningSchedule(),
            $validator,
        );

        $restoredTask = $action->manual($task->id);

        $this->assertSame('pending', $restoredTask->status);
        $this->assertNull($restoredTask->started_at);
        $this->assertNull($restoredTask->completed_at);
        $this->assertNull($restoredTask->completed_by_user_id);
        $this->assertSame(
            $checkOut->copy()->setTime(11, 30)->format('Y-m-d H:i:s'),
            $restoredTask->scheduled_start_at->format('Y-m-d H:i:s')
        );
        $this->assertNull($restoredTask->scheduled_end_at);
        $this->assertNull($restoredTask->deleted_at);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'pending',
            'started_at' => null,
            'completed_at' => null,
            'deleted_at' => null,
        ]);
    }

    public function test_it_does_not_restore_a_task_when_its_property_is_inactive(): void
    {
        $checkIn = Carbon::now()->addDays(10)->setTime(15, 0);
        $checkOut = Carbon::now()->addDays(12)->setTime(10, 0);

        $snapshot = new BookingSnapshot(
            bookingId: 1,
            propertyId: 10,
            checkIn: $checkIn,
            checkOut: $checkOut,
            nextCheckIn: null,
            propertyActive: false,
        );

        $task = Task::create([
            'booking_id' => 1,
            'property_id' => 10,
            'type' => 'checkout_cleaning',
            'status' => 'cancelled',
            'scheduled_start_at' => Carbon::now()->subDays(2),
            'scheduled_end_at' => Carbon::now()->subDay(),
        ]);
        $task->delete();

        $bookings = $this->createMock(BookingDataProvider::class);
        $bookings->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($snapshot);

        $action = new RestoreTask(
            $bookings,
            new CleaningSchedule(),
            new TaskGenerationValidator(),
        );

        try {
            $action->manual($task->id);
            $this->fail('A restauração de uma tarefa numa propriedade inativa devia ser rejeitada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('property_id', $exception->errors());
        }

        $unchangedTask = Task::withTrashed()->findOrFail($task->id);

        $this->assertSame('cancelled', $unchangedTask->status);
        $this->assertNotNull($unchangedTask->deleted_at);
    }

}