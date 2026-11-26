<?php

namespace App\Modules\Billing\Services;

use App\Models\Task;
use App\Modules\Billing\Models\ServiceEntry;
use App\Modules\Properties\Models\Property;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Creates the single service entry of an eligible task.
 *
 * Eligible: completed, not deleted and with a service provider.
 * Idempotent: an existing entry is returned as it is, and the unique task_id
 * protects against two requests creating it at the same time.
 */
class RecordServiceEntry
{
    public function __construct(private AppliedRateResolver $rates) {}

    public function forTask(int $taskId): ?ServiceEntry
    {
        $existing = ServiceEntry::query()->where('task_id', $taskId)->first();

        if ($existing) {
            return $existing;
        }

        $task = Task::query()->find($taskId);

        if (! $task || $task->status !== 'completed' || $task->service_provider_id === null) {
            Log::info('Service entry skipped: task is not eligible.', [
                'task_id' => $taskId,
                'status' => $task?->status,
                'service_provider_id' => $task?->service_provider_id,
            ]);

            return null;
        }

        $property = Property::query()->findOrFail($task->property_id);
        $serviceDate = $this->serviceDate($task, $property);
        $applied = $this->rates->resolve($task, $property, $serviceDate);

        if (! $applied) {
            Log::warning('Service entry skipped: no agreed amount, rate or base amount.', [
                'task_id' => $task->id,
                'property_id' => $property->id,
                'task_type' => $task->type,
            ]);

            return null;
        }

        try {
            return ServiceEntry::create([
                'task_id' => $task->id,
                'service_provider_id' => $task->service_provider_id,
                'property_id' => $property->id,
                'task_type' => $task->type,
                'service_date' => $serviceDate->toDateString(),
                'amount' => $applied->amount,
                'currency' => $applied->currency,
                'amount_source' => $applied->source,
                'rate_id' => $applied->rateId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return ServiceEntry::query()->where('task_id', $task->id)->firstOrFail();
        }
    }

    // Local date of the scheduled start in the property's timezone.
    private function serviceDate(Task $task, Property $property): Carbon
    {
        $timezone = $property->timezone ?: Property::DEFAULT_TIMEZONE;

        return $task->scheduled_start_at->copy()->setTimezone($timezone)->startOfDay();
    }
}
