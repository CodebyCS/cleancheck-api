<?php

namespace App\Modules\Properties\Http\Requests\Concerns;

use App\Modules\Properties\Models\ServiceProvider;

trait AuthorizesServiceProviderSchedule
{
    /**
     * Admin, supervisor or the provider themself can manage the schedule.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $serviceProvider = $this->route('serviceProvider');

        if (! $user || ! $serviceProvider instanceof ServiceProvider) {
            return false;
        }

        return $user->hasRole('admin', 'supervisor') || $user->id === $serviceProvider->user_id;
    }
}
