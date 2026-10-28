<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Requests;

use App\Modules\Reservations\Enums\IcalErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class IcalUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'supervisor') ?? false;
    }

    public function rules(): array
    {
        return [
            //validar se o ficheiro é do tipo .ics e se o property_id é valido (existe na base de dados)
            'file' => ['bail', 'required', 'file', 'extensions:ics'],
            'property_id' => ['bail', 'required', 'integer', 'min:1'],
        ];
    }
    
    // Substitui o método failedValidation para retornar u erro do contrato
    protected function failedValidation(Validator $validator): void
    {
        $failed = $validator->failed();

        [$code, $message] = match (true) {
            isset($failed['file']['Extensions']) => [
                IcalErrorCode::InvalidFileType,
                'O ficheiro tem de ter a extensão .ics.',
            ],
            isset($failed['file']) => [
                IcalErrorCode::NoFile,
                'Nenhum ficheiro foi enviado.',
            ],
            default => [
                IcalErrorCode::PropertyNotFound,
                'O imóvel indicado não existe.',
            ],
        };

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => $code->value,
                'message' => $message,
            ],
        ], 422));
    }
}