<?php

namespace App\Providers;

use App\Models\Task;
use App\Modules\Properties\Observers\AssignDefaultProviderOnTaskCreating;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Events\BookingDeleted;
use App\Modules\Reservations\Events\BookingImported;

use App\Modules\Reservations\Listeners\RecordNoticesOnTaskChange;
use App\Modules\Tasks\Events\TaskAssigned;
use App\Modules\Tasks\Events\TaskCancelled;

use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Listeners\RecordNoticesOnBookingCancelled;
use App\Modules\Reservations\Listeners\RecordNoticesOnBookingUpdated;
use App\Modules\Tasks\Contracts\BookingDataProvider;
use App\Modules\Tasks\Contracts\PropertyScheduleProvider;
use App\Modules\Tasks\Contracts\ProviderOpenTasks;
use App\Modules\Tasks\Infrastructure\DatabasePropertyScheduleProvider;
use App\Modules\Tasks\Infrastructure\EloquentBookingDataProvider;
use App\Modules\Tasks\Infrastructure\EloquentProviderOpenTasks;
use App\Modules\Tasks\Listeners\CancelTasksOnBookingCancelled;
use App\Modules\Tasks\Listeners\CancelTasksOnPropertyDeactivated;
use App\Modules\Tasks\Listeners\CreateCheckoutCleaningOnBookingImported;
use App\Modules\Tasks\Listeners\UpdateTasksOnBookingUpdated;
use App\Modules\Properties\Events\PropertyDeactivated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            BookingDataProvider::class,
            EloquentBookingDataProvider::class,
        );

        $this->app->bind(
            PropertyScheduleProvider::class,
            DatabasePropertyScheduleProvider::class,
        );

        $this->app->bind(
            ProviderOpenTasks::class,
            EloquentProviderOpenTasks::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            BookingImported::class,
            CreateCheckoutCleaningOnBookingImported::class,
        );

        Event::listen(
            PropertyDeactivated::class,
            CancelTasksOnPropertyDeactivated::class,
        );

        // Affected users get an internal notice.
        Event::listen(BookingUpdated::class, RecordNoticesOnBookingUpdated::class);
        Event::listen(BookingCancelled::class, RecordNoticesOnBookingCancelled::class);

        Event::listen(TaskAssigned::class, [RecordNoticesOnTaskChange::class, 'handleAssigned']);
        Event::listen(TaskCancelled::class, [RecordNoticesOnTaskChange::class, 'handleCancelled']);

        // New tasks get the property's default provider (only when active).
        Task::observe(AssignDefaultProviderOnTaskCreating::class);

        Event::listen(
            BookingUpdated::class,
            UpdateTasksOnBookingUpdated::class,
        );

        Event::listen(
            BookingCancelled::class,
            CancelTasksOnBookingCancelled::class,
        );
    }
}