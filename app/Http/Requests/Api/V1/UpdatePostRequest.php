<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['sometimes', Rule::enum(Platform::class)],
            'body' => ['sometimes', 'nullable', 'string'],
            'media' => ['sometimes', 'nullable', 'array'],
            'media.*' => ['url:http,https'],
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
