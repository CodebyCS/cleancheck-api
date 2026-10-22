<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Modules\Tasks\Actions\CreateCheckoutCleaningTask;
use App\Modules\Tasks\Contracts\BookingDataProvider;
use App\Modules\Tasks\Services\CleaningSchedule;
use App\Modules\Tasks\Support\BookingSnapshot;
use App\Modules\Tasks\Validators\TaskGenerationValidator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
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

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

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

    private function snapshot(): BookingSnapshot
    {
        return new BookingSnapshot(
            bookingId: 1,
            propertyId: 10,
            checkIn: Carbon::parse('2026-10-18 15:00:00'),
            checkOut: Carbon::parse('2026-10-20 10:00:00'),
            nextCheckIn: null,
        );
    }

    private function makeAction(
        ?BookingSnapshot $snapshot
    ): CreateCheckoutCleaningTask {
        $bookings = $this->createMock(BookingDataProvider::class);

        $bookings->method('find')
            ->with(1)
            ->willReturn($snapshot);

        $validator = $this->createMock(TaskGenerationValidator::class);

        $validator->method('validateCheckoutCleaning')
            ->willReturnCallback(static function (BookingSnapshot $data): void {
                if (!$data->propertyId || !$data->checkOut) {
                    throw ValidationException::withMessages([
                        'booking_id' => 'Dados inválidos para criar a tarefa.',
                    ]);
                }
            });

        return new CreateCheckoutCleaningTask(
            $bookings,
            new CleaningSchedule(),
            $validator,
        );
    }

    public function test_it_creates_a_checkout_cleaning_task(): void
    {
        $action = $this->makeAction($this->snapshot());

        $task = $action->execute(1);

        $this->assertInstanceOf(Task::class, $task);
        $this->assertSame('checkout_cleaning', $task->type);
        $this->assertSame('pending', $task->status);
        $this->assertSame(1, $task->booking_id);
        $this->assertSame(10, $task->property_id);

        $this->assertSame(
            '2026-10-20 11:30:00',
            $task->scheduled_start_at->format('Y-m-d H:i:s')
        );

        $this->assertNull($task->scheduled_end_at);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'booking_id' => 1,
            'property_id' => 10,
            'type' => 'checkout_cleaning',
        ]);
    }

    public function test_it_returns_existing_task_without_creating_a_duplicate(): void
    {
        $existing = Task::create([
            'booking_id' => 1,
            'property_id' => 10,
            'type' => 'checkout_cleaning',
            'status' => 'pending',
            'scheduled_start_at' => '2026-10-20 11:30:00',
        ]);

        $action = $this->makeAction($this->snapshot());

        $result = $action->execute(1);

        $this->assertSame($existing->id, $result->id);
        $this->assertSame(1, Task::count());
    }

    public function test_it_does_not_recreate_a_soft_deleted_task(): void
    {
        $existing = Task::create([
            'booking_id' => 1,
            'property_id' => 10,
            'type' => 'checkout_cleaning',
            'status' => 'pending',
            'scheduled_start_at' => '2026-10-20 11:30:00',
        ]);

        $existing->delete();

        $action = $this->makeAction($this->snapshot());

        $result = $action->execute(1);

        $this->assertNull($result);
        $this->assertSame(1, Task::withTrashed()->count());
        $this->assertSoftDeleted('tasks', ['id' => $existing->id]);
    }

    public function test_it_rejects_a_missing_booking_snapshot(): void
    {
        $action = $this->makeAction(null);

        try {
            $action->execute(1);
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Reserva não encontrada.',
                $exception->errors()['booking_id'][0]
            );
        }

        $this->assertSame(0, Task::count());
    }

    public function test_it_does_not_create_a_task_when_validation_fails(): void
    {
        $snapshot = new BookingSnapshot(
            bookingId: 1,
            propertyId: null,
            checkIn: Carbon::parse('2026-10-18 15:00:00'),
            checkOut: Carbon::parse('2026-10-20 10:00:00'),
            nextCheckIn: null,
        );

        $action = $this->makeAction($snapshot);

        try {
            $action->execute(1);
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Dados inválidos para criar a tarefa.',
                $exception->errors()['booking_id'][0]
            );
        }

        $this->assertSame(0, Task::count());
    }
}