<?php

namespace App\Modules\Billing\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;

class MonthlyReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'supervisor') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Defaults to the current month.
            'month' => ['sometimes', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'service_provider_id' => ['sometimes', 'integer', 'exists:service_providers,id'],
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'month.regex' => 'O mês deve estar no formato AAAA-MM.',
            'service_provider_id.integer' => 'O prestador é inválido.',
            'service_provider_id.exists' => 'O prestador não existe.',
        ];
    }

    public function month(): string
    {
        return $this->validated('month') ?? now()->format('Y-m');
    }

    public function serviceProviderId(): ?int
    {
        $id = $this->validated('service_provider_id');

        return $id === null ? null : (int) $id;
    }
}
