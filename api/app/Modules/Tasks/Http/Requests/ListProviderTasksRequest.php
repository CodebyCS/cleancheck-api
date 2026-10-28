<?php

namespace App\Modules\Tasks\Http\Requests;

class ListProviderTasksRequest extends ListTasksRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'deleted' => ['prohibited'],
        ]);
    }
}