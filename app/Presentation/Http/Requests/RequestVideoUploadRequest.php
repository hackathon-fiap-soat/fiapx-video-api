<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Corpo de POST /api/videos (schema VideoUploadRequest do OpenAPI). */
final class RequestVideoUploadRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'min:1', 'max:255', 'regex:/\.(mp4|avi|mov|mkv|wmv|flv|webm)$/i'],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:524288000'],
            'content_type' => ['present', 'nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'filename.required' => 'Informe o nome do arquivo.',
            'filename.regex' => 'O formato do arquivo não é aceito. Use mp4, avi, mov, mkv, wmv, flv ou webm.',
            'filename.max' => 'O nome do arquivo deve ter no máximo 255 caracteres.',
            'size_bytes.required' => 'Informe o tamanho do arquivo.',
            'size_bytes.integer' => 'O tamanho do arquivo deve ser um número inteiro de bytes.',
            'size_bytes.min' => 'O arquivo está vazio.',
            'size_bytes.max' => 'O arquivo deve ter no máximo 500 MB.',
            'content_type.present' => 'Informe o tipo do arquivo.',
            'content_type.max' => 'O tipo do arquivo deve ter no máximo 100 caracteres.',
        ];
    }
}
