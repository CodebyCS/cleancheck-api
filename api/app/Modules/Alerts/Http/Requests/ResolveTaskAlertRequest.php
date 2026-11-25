<?php

declare(strict_types=1);

namespace App\Modules\Alerts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveTaskAlertRequest extends FormRequest
{
    // Roles are checked by the role:admin,supervisor route middleware.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indique o motivo da resolução.',
            'reason.min' => 'O motivo tem de ter pelo menos 3 caracteres.',
            'reason.max' => 'O motivo não pode ter mais de 255 caracteres.',
        ];
    }
}