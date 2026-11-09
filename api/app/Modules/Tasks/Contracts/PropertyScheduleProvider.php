<?php

namespace App\Modules\Tasks\Contracts;

use App\Modules\Tasks\Support\PropertySchedule;

interface PropertyScheduleProvider
{
    public function find(int $propertyId): ?PropertySchedule;
}
