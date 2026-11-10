<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Modules\Tasks\Contracts\BookingDataProvider;
use App\Modules\Tasks\Contracts\PropertyScheduleProvider;
use App\Modules\Tasks\Infrastructure\DatabasePropertyScheduleProvider;
use App\Modules\Tasks\Infrastructure\EloquentBookingDataProvider;
use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Listeners\RecordNoticesOnBookingCancelled;
use App\Modules\Reservations\Listeners\RecordNoticesOnBookingUpdated;
use App\Modules\Tasks\Listeners\CreateCheckoutCleaningOnBookingImported;
use Illuminate\Support\Facades\Event;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BookingDataProvider::class, EloquentBookingDataProvider::class);
        $this->app->bind(PropertyScheduleProvider::class, DatabasePropertyScheduleProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(BookingImported::class, CreateCheckoutCleaningOnBookingImported::class);
        Event::listen(BookingUpdated::class, RecordNoticesOnBookingUpdated::class);
        Event::listen(BookingCancelled::class, RecordNoticesOnBookingCancelled::class);
    }
}
