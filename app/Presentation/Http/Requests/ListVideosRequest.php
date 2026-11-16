<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Domain\Video\ValueObjects\VideoStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query de GET /api/videos. */
final class ListVideosRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(VideoStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status' => 'Status inválido.',
            'page.*' => 'A página deve ser um número a partir de 1.',
            'per_page.*' => 'Itens por página: de 1 a 100.',
        ];
    }
}
