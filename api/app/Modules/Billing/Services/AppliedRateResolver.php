<?php

namespace App\Modules\Billing\Services;

use App\Models\Task;
use App\Modules\Billing\Models\Rate;
use App\Modules\Billing\Models\ServiceEntry;
use App\Modules\Billing\Support\AppliedAmount;
use App\Modules\Properties\Models\Property;
use Illuminate\Support\Carbon;

/**
 * Picks the amount of a service, in this order:
 * 1. the amount agreed on the task (set by admin/supervisor);
 * 2. the rate in force on the service date: the property's own rate first, then the general one;
 * 3. the base cleaning amount of the property.
 * Returns null when none is defined, so nothing is recorded with a guessed value.
 */
class AppliedRateResolver
{
    public function resolve(Task $task, Property $property, Carbon $serviceDate): ?AppliedAmount
    {
        if ($task->agreed_amount !== null) {
            return new AppliedAmount(
                amount: (string) $task->agreed_amount,
                currency: $this->currencyFor($task, $property, $serviceDate),
                source: ServiceEntry::SOURCE_TASK_AGREED_AMOUNT,
            );
        }

        $rate = $this->rateInForce($task->type, $property->id, $serviceDate);

        if ($rate) {
            return new AppliedAmount(
                amount: (string) $rate->amount,
                currency: $rate->currency,
                source: ServiceEntry::SOURCE_RATE,
                rateId: $rate->id,
            );
        }

        if ($property->base_cleaning_amount !== null) {
            return new AppliedAmount(
                amount: (string) $property->base_cleaning_amount,
                currency: Rate::DEFAULT_CURRENCY,
                source: ServiceEntry::SOURCE_PROPERTY_BASE,
            );
        }

        return null;
    }

    /**
     * Latest rate that started on or before the service date; a property rate wins over a general one.
     */
    public function rateInForce(string $taskType, int $propertyId, Carbon $serviceDate): ?Rate
    {
        return Rate::query()
            ->where('task_type', $taskType)
            ->whereDate('valid_from', '<=', $serviceDate->toDateString())
            ->where(fn ($query) => $query->where('property_id', $propertyId)->orWhereNull('property_id'))
            ->orderByRaw('property_id IS NULL')
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }

    // The agreed amount has no currency of its own: it follows the rate in force, or the default.
    private function currencyFor(Task $task, Property $property, Carbon $serviceDate): string
    {
        return $this->rateInForce($task->type, $property->id, $serviceDate)?->currency
            ?? Rate::DEFAULT_CURRENCY;
    }
}
