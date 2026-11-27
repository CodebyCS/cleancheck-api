<?php

namespace App\Modules\Tasks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectTaskExecutionRequest extends FormRequest
{
    // Authentication and admin/supervisor access are enforced by route middleware.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string']];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indica o motivo e os passos de correção pedidos ao prestador.',
            'reason.string' => 'O motivo tem de ser texto.',
        ];
    }
}
