<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ReorderListRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'post_ids' => ['required', 'array'],
            'post_ids.*' => ['integer', 'distinct'],
        ];
    }
}
