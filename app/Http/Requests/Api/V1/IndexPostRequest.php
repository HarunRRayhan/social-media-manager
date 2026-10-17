<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPostRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(PostStatus::class)],
        ];
    }
}
