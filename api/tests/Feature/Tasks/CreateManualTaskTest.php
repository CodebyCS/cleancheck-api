<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Modules\Tasks\Actions\CreateManualTask;
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

class CreateManualTaskTest extends TestCase
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
            checkIn: Carbon::now()->addDays(2)->setTime(15, 0),
            checkOut: Carbon::now()->addDays(4)->setTime(10, 0),
            nextCheckIn: null,
            propertyActive: true,
        );
    }

    private function makeAction(
        ?BookingSnapshot $snapshot
    ): CreateManualTask {
        $bookings = $this->createMock(BookingDataProvider::class);

        $bookings->method('find')
            ->with(1)
            ->willReturn($snapshot);

        $validator = $this->createMock(TaskGenerationValidator::class);

        $validator->method('validateInspection')
            ->willReturnCallback(static function (BookingSnapshot $data): void {
                if (!$data->propertyId || !$data->checkIn) {
                    throw ValidationException::withMessages([
                        'booking_id' => 'Dados inválidos para criar a inspeção.',
                    ]);
                }

                if ($data->checkIn->copy()->setTime(14, 30)->isPast()) {
                    throw ValidationException::withMessages([
                        'check_in' => 'O prazo da inspeção (14h30 do dia do check-in) já passou.',
                    ]);
                }
            });

        return new CreateManualTask(
            $bookings,
            new CleaningSchedule(),
            $validator,
        );
    }

    public function test_it_creates_a_manual_inspection_task(): void
    {
        $action = $this->makeAction($this->snapshot());

        $task = $action->execute(1);

        $this->assertInstanceOf(Task::class, $task);
        $this->assertSame('checkin_inspection', $task->type);
        $this->assertSame('pending', $task->status);
        $this->assertSame(1, $task->booking_id);
        $this->assertSame(10, $task->property_id);

        $this->assertNotNull($task->scheduled_start_at);
        $this->assertNotNull($task->scheduled_end_at);

        $this->assertSame(
            $this->snapshot()->checkIn->format('Y-m-d'),
            $task->scheduled_end_at->format('Y-m-d')
        );

        $this->assertSame(
            '14:30:00',
            $task->scheduled_end_at->format('H:i:s')
        );

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'booking_id' => 1,
            'property_id' => 10,
            'type' => 'checkin_inspection',
        ]);
    }

    public function test_it_rejects_an_existing_inspection(): void
    {
        Task::create([
            'booking_id' => 1,
            'property_id' => 10,
            'type' => 'checkin_inspection',
            'status' => 'pending',
            'scheduled_start_at' => now(),
        ]);

        $action = $this->makeAction($this->snapshot());

        try {
            $action->execute(1);
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Já existe uma tarefa de inspeção para esta reserva.',
                $exception->errors()['booking_id'][0]
            );
        }

        $this->assertSame(1, Task::count());
    }

    public function test_it_rejects_a_soft_deleted_inspection(): void
    {
        $existing = Task::create([
            'booking_id' => 1,
            'property_id' => 10,
            'type' => 'checkin_inspection',
            'status' => 'pending',
            'scheduled_start_at' => now(),
        ]);

        $existing->delete();

        $action = $this->makeAction($this->snapshot());

        try {
            $action->execute(1);
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Já existe uma inspeção eliminada para esta reserva. Use a opção de restaurar.',
                $exception->errors()['booking_id'][0]
            );
        }

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

    public function test_it_does_not_create_an_inspection_when_validation_fails(): void
    {
        $snapshot = new BookingSnapshot(
            bookingId: 1,
            propertyId: null,
            checkIn: Carbon::now()->addDays(2),
            checkOut: null,
            nextCheckIn: null,
        );

        $action = $this->makeAction($snapshot);

        try {
            $action->execute(1);
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Dados inválidos para criar a inspeção.',
                $exception->errors()['booking_id'][0]
            );
        }

        $this->assertSame(0, Task::count());
    }
}