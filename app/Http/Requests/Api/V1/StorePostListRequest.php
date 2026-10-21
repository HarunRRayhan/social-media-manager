<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostListRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Optional: a list of one social set. A set token defaults to its own set.
            'social_set_id' => ['nullable', 'integer', Rule::exists('social_sets', 'id')->where('team_id', $team->id)->whereNull('deleted_at')],
        ];
    }
}
