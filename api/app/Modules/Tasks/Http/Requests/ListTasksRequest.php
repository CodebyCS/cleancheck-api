<?php

namespace App\Modules\Tasks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from'        => ['sometimes', 'date'],
            'to'          => ['sometimes', 'date', 'after_or_equal:from'],
            'property_id' => ['sometimes', 'integer'],
            'status'      => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'type'        => ['sometimes', Rule::in(['checkout_cleaning', 'checkin_inspection'])],
            'deleted'     => ['sometimes', Rule::in(['only'])],
            'per_page'    => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}