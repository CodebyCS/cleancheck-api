<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaskImpedimentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $task = $this->route('task');
        $provider = $user?->serviceProvider;

        return $user?->hasRole('service_provider') === true
            && $task instanceof Task
            && $provider !== null
            && (int) $task->service_provider_id === (int) $provider->id;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
