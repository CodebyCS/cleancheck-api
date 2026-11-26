<?php

namespace App\Modules\Tasks\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreCheckoutInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('service_provider') === true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'responses' => ['present', 'array', 'max:200', 'list'],
            'responses.*' => ['required', 'array:key,value'],
            'responses.*.key' => ['required', 'string', 'max:120', 'distinct:strict'],
            'responses.*.value' => ['present', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && ! is_scalar($value)) {
                    $fail('A resposta deve ser texto, número, booleano ou null.');
                } elseif (is_string($value) && mb_strlen($value) > 2000) {
                    $fail('A resposta não pode exceder 2000 caracteres.');
                }
            }],
            'observations' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'evidence_ids' => ['sometimes', 'array', 'list', 'max:100'],
            'evidence_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'user_id' => ['prohibited'],
            'task_id' => ['prohibited'],
        ];
    }
}
