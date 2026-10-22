<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Modules\Tasks\Contracts\BookingDataProvider;
use App\Modules\Tasks\Infrastructure\EloquentBookingDataProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BookingDataProvider::class, EloquentBookingDataProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
