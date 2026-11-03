<?php

namespace App\Modules\Properties\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;

class UploadServiceProviderPhotoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'supervisor') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'photo.required' => 'Escolha uma fotografia.',
            'photo.image' => 'O ficheiro tem de ser uma imagem.',
            'photo.mimes' => 'A fotografia tem de ser JPG, PNG ou WEBP.',
            'photo.max' => 'A fotografia não pode ter mais de 2 MB.',
        ];
    }
}
