<?php

namespace App\Modules\Evidence\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use Override;

/**
 * Trial upload used to test the camera and the upload from a real device.
 * It is not the final evidence flow and is not linked to a task.
 */
class StoreEvidenceTrialRequest extends FormRequest
{
    public const PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public const VIDEO_MIME_TYPES = ['video/mp4', 'video/quicktime'];

    // Limits in kilobytes, as used by Laravel's "max" rule.
    public const MAX_PHOTO_KB = 10 * 1024;

    public const MAX_VIDEO_KB = 50 * 1024;

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
            'file' => [
                'required',
                'file',
                'mimetypes:'.implode(',', [...self::PHOTO_MIME_TYPES, ...self::VIDEO_MIME_TYPES]),
                'max:'.self::MAX_VIDEO_KB,
            ],
            // Free text to record the trial, e.g. "Pixel 7, Android 15, 4G".
            'device' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('file');

            if (!$file instanceof UploadedFile || $validator->errors()->has('file')) {
                return;
            }

            // Photos have a lower limit than videos.
            if (self::isPhoto($file) && $file->getSize() > self::MAX_PHOTO_KB * 1024) {
                $validator->errors()->add('file', 'A fotografia não pode ter mais de 10 MB.');
            }
        });
    }

    public static function isPhoto(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), self::PHOTO_MIME_TYPES, true);
    }

    #[Override]
    public function messages(): array
    {
        return [
            'file.required' => 'Escolha uma fotografia ou um vídeo.',
            'file.file' => 'O envio do ficheiro falhou. Tente novamente.',
            'file.uploaded' => 'O ficheiro não foi recebido. Pode ser maior do que o servidor aceita.',
            'file.mimetypes' => 'Formato não aceite. Use JPG, PNG, WEBP ou HEIC para fotografias e MP4 ou MOV para vídeos.',
            'file.max' => 'O ficheiro não pode ter mais de 50 MB.',
            'device.max' => 'A descrição do aparelho não pode ter mais de 120 caracteres.',
        ];
    }
}
