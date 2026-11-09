<?php

namespace App\Modules\Tasks\Contracts;

interface ProviderOpenTasks
{
    /** Number of tasks (pending or in progress) still assigned to the service provider. */
    public function count(int $serviceProviderId): int;
}