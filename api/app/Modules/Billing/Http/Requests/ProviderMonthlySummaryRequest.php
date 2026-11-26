<?php

namespace App\Modules\Billing\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;

class ProviderMonthlySummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('service_provider') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Defaults to the current month.
            'month' => ['sometimes', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'month.regex' => 'O mês deve estar no formato AAAA-MM.',
        ];
    }
}
