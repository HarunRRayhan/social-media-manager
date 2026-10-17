<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApiTokenStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Empty means the whole team; otherwise one of the current team's sets.
            'social_set_id' => [
                'nullable',
                'integer',
                Rule::exists('social_sets', 'id')->where('team_id', $this->user()->currentTeam()->id),
            ],
        ];
    }
}
