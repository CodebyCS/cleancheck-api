<?php

namespace App\Modules\Tasks\Listeners;

use App\Modules\Properties\Events\PropertyDeactivated;
use App\Modules\Tasks\Actions\CancelTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class CancelTasksOnPropertyDeactivated implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(
        private readonly CancelTask $action,
    ) {
    }

    public function handle(PropertyDeactivated $event): void
    {
        $this->action->forProperty($event->propertyId);
    }

    public function failed(PropertyDeactivated $event, Throwable $e): void
    {
        Log::error('Falha definitiva ao cancelar as tasks da propriedade desativada.', [
            'property_id' => $event->propertyId,
            'error'       => $e->getMessage(),
        ]);
    }
}