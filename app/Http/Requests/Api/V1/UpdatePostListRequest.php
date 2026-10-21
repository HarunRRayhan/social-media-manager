<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostListRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->user();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'social_set_id' => ['sometimes', 'nullable', 'integer', Rule::exists('social_sets', 'id')->where('team_id', $team->id)->whereNull('deleted_at')],
        ];
    }
}
