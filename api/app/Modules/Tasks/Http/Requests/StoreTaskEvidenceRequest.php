<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Evidence\Http\Requests\StoreEvidenceTrialRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class StoreTaskEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('service_provider') ?? false;
    }

    public function rules(): array
    {
        $mimeTypes = implode(',', [
            ...StoreEvidenceTrialRequest::PHOTO_MIME_TYPES,
            ...StoreEvidenceTrialRequest::VIDEO_MIME_TYPES,
        ]);

        return [
            'file' => [
                'required',
                'file',
                "mimetypes:{$mimeTypes}",
                'max:' . StoreEvidenceTrialRequest::MAX_VIDEO_KB,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('file');

            if (
                !$file instanceof UploadedFile
                || $validator->errors()->has('file')
            ) {
                return;
            }

            if (
                StoreEvidenceTrialRequest::isPhoto($file)
                && $file->getSize()
                    > StoreEvidenceTrialRequest::MAX_PHOTO_KB * 1024
            ) {
                $validator->errors()->add(
                    'file',
                    'A fotografia não pode ter mais de 10 MB.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Escolha uma fotografia ou um vídeo.',
            'file.file' => 'O envio do ficheiro falhou. Tente novamente.',
            'file.uploaded' => 'O ficheiro não foi recebido ou excede o limite do servidor.',
            'file.mimetypes' => 'Formato não aceite. Use JPG, PNG, WEBP ou HEIC para fotografias e MP4 ou MOV para vídeos.',
            'file.max' => 'O ficheiro não pode ter mais de 50 MB.',
        ];
    }
}