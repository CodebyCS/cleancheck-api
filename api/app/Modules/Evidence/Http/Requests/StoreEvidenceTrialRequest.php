<?php

namespace App\Modules\Evidence\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use Override;

/**
 * Trial upload used to test camera capture and upload from a real device.
 * It is not the final evidence workflow.
 */
class StoreEvidenceTrialRequest extends FormRequest
{
    public const PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public const VIDEO_MIME_TYPES = ['video/mp4', 'video/quicktime'];

    // Limits in kilobytes, as used by Laravel's "max" rule.
    public const MAX_PHOTO_KB = 10 * 1024;

    public const MAX_VIDEO_KB = 50 * 1024;

    public const MAX_VIDEO_SECONDS = 60;

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
                return;
            }

            if (self::isVideo($file)) {
                $duration = $this->videoDurationSeconds($file);

                if ($duration === null) {
                    $validator->errors()->add('file', 'Não foi possível determinar a duração do vídeo. Envia um ficheiro MP4 ou MOV válido.');
                } elseif ($duration > self::MAX_VIDEO_SECONDS) {
                    $validator->errors()->add('file', 'O vídeo não pode ter mais de 60 segundos.');
                }
            }
        });
    }

    public static function isPhoto(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), self::PHOTO_MIME_TYPES, true);
    }

    public static function isVideo(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), self::VIDEO_MIME_TYPES, true);
    }

    private function videoDurationSeconds(UploadedFile $file): ?float
    {
        $path = $file->getRealPath();
        $fileSize = $file->getSize();

        if (!is_string($path) || !is_int($fileSize)) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            while (($atom = $this->readAtomHeader($handle, $fileSize)) !== null) {
                if ($atom['type'] === 'moov') {
                    return $this->durationFromMovieAtom($handle, $atom['end']);
                }

                if (fseek($handle, $atom['end']) !== 0) {
                    return null;
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    /** @return array{type: string, end: int}|null */
    private function readAtomHeader($handle, int $boundary): ?array
    {
        $start = ftell($handle);

        if (!is_int($start) || $start + 8 > $boundary) {
            return null;
        }

        $header = fread($handle, 8);

        if (!is_string($header) || strlen($header) !== 8) {
            return null;
        }

        $parts = unpack('Nsize/a4type', $header);

        if (!is_array($parts) || !is_int($parts['size']) || !is_string($parts['type'])) {
            return null;
        }

        $headerSize = 8;
        $atomSize = $parts['size'];

        if ($atomSize === 1) {
            $extended = fread($handle, 8);

            if (!is_string($extended) || strlen($extended) !== 8) {
                return null;
            }

            $extendedParts = unpack('Nhigh/Nlow', $extended);
            if (!is_array($extendedParts)) {
                return null;
            }

            $remaining = $boundary - $start;
            $maxHigh = intdiv($remaining, 4294967296);
            $remainingLow = $remaining % 4294967296;

            if ($extendedParts['high'] > $maxHigh
                || ($extendedParts['high'] === $maxHigh && $extendedParts['low'] > $remainingLow)) {
                return null;
            }

            $atomSize = ($extendedParts['high'] * 4294967296) + $extendedParts['low'];
            $headerSize = 16;
        } elseif ($atomSize === 0) {
            $atomSize = $boundary - $start;
        }

        if ($atomSize < $headerSize || $atomSize > $boundary - $start) {
            return null;
        }

        return ['type' => $parts['type'], 'end' => $start + $atomSize];
    }

    private function durationFromMovieAtom($handle, int $movieAtomEnd): ?float
    {
        while (($atom = $this->readAtomHeader($handle, $movieAtomEnd)) !== null) {
            if ($atom['type'] !== 'mvhd') {
                if (fseek($handle, $atom['end']) !== 0) {
                    return null;
                }
                continue;
            }

            $fullBoxPosition = ftell($handle);

            if (!is_int($fullBoxPosition) || $fullBoxPosition + 4 > $atom['end']) {
                return null;
            }

            $fullBox = fread($handle, 4);
            if (!is_string($fullBox) || strlen($fullBox) !== 4) {
                return null;
            }

            $version = ord($fullBox[0]);
            $metadataSize = $version === 0 ? 16 : ($version === 1 ? 28 : null);

            if ($metadataSize === null) {
                return null;
            }

            $metadataPosition = ftell($handle);

            if (!is_int($metadataPosition) || $metadataPosition + $metadataSize > $atom['end']) {
                return null;
            }

            $metadata = fread($handle, $metadataSize);

            if (!is_string($metadata) || strlen($metadata) !== $metadataSize) {
                return null;
            }

            if ($version === 0) {
                $values = unpack('Ncreation/Nmodification/Ntimescale/Nduration', $metadata);
                if (!is_array($values)) {
                    return null;
                }
                $timescale = $values['timescale'];
                $duration = $values['duration'];
            } else {
                $values = unpack('NcreationHigh/NcreationLow/NmodificationHigh/NmodificationLow/Ntimescale/NdurationHigh/NdurationLow', $metadata);
                if (!is_array($values)) {
                    return null;
                }
                $timescale = $values['timescale'];
                $duration = ($values['durationHigh'] * 4294967296) + $values['durationLow'];
            }

            return $timescale > 0 && $duration > 0 ? $duration / $timescale : null;
        }

        return null;
    }

    #[Override]
    public function messages(): array
    {
        return [
            'file.required' => 'Escolha uma fotografia ou um vídeo.',
            'file.file' => 'O envio do ficheiro falhou. Tente novamente.',
            'file.uploaded' => 'O ficheiro não foi recebido. Pode ser maior do que o servidor aceita.',
            'file.mimetypes' => 'Formato não aceite. Use JPG, PNG, WEBP, HEIC ou HEIF para fotografias e MP4 ou MOV para vídeos.',
            'file.max' => 'O ficheiro não pode ter mais de 50 MB.',
            'device.max' => 'A descrição do aparelho não pode ter mais de 120 caracteres.',
        ];
    }
}
