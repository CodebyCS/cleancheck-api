<?php

namespace App\Modules\Tasks\Contracts;

use App\Modules\Tasks\Support\BookingSnapshot;

interface BookingDataProvider
{
    public function find(int $bookingId): ?BookingSnapshot;
}