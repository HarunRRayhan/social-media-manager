<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::enum(Platform::class)],
            'body' => ['nullable', 'string'],
            'media' => ['nullable', 'array'],
            'media.*' => ['url:http,https'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
