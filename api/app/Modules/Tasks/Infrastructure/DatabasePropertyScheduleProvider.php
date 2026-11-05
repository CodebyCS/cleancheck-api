<?php

namespace App\Modules\Tasks\Infrastructure;

use App\Modules\Tasks\Contracts\PropertyScheduleProvider;
use App\Modules\Tasks\Support\PropertySchedule;
use Illuminate\Support\Facades\DB;

/**
 * Reads only the columns the Tasks module needs, without depending on the Properties module.
 * Columns used: properties.default_check_out_time, default_check_in_time, timezone.
 */
class DatabasePropertyScheduleProvider implements PropertyScheduleProvider
{
    public function find(int $propertyId): ?PropertySchedule
    {
        $row = DB::table('properties')
            ->select(['default_check_out_time', 'default_check_in_time', 'timezone'])
            ->where('id', $propertyId)
            ->first();

        if (!$row) {
            return null;
        }

        return new PropertySchedule(
            checkOutTime: $row->default_check_out_time,
            checkInTime: $row->default_check_in_time,
            timezone: $row->timezone ?: PropertySchedule::DEFAULT_TIMEZONE,
        );
    }
}
