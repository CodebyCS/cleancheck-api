<?php

namespace App\Modules\Billing\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;

class StoreServiceEntryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'supervisor') ?? false;
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Signed difference: negative reduces the entry, positive increases it.
            'amount' => ['required', 'numeric', 'decimal:0,2', 'between:-99999999.99,99999999.99', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'amount.required' => 'Indique o valor do ajuste.',
            'amount.numeric' => 'O valor do ajuste deve ser um número.',
            'amount.decimal' => 'O valor do ajuste pode ter no máximo 2 casas decimais.',
            'amount.between' => 'O valor do ajuste é demasiado elevado.',
            'amount.not_in' => 'O valor do ajuste não pode ser zero.',
            'reason.required' => 'Indique o motivo do ajuste.',
            'reason.max' => 'O motivo pode ter no máximo 500 caracteres.',
        ];
    }
}
