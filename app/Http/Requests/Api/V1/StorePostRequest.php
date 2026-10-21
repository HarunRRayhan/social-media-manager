<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Platform;
use App\Http\Requests\Api\V1\Concerns\ListRules;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    use ListRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->user();

        return [
            // Required for a whole-team token; a social-set token defaults to its own set.
            'social_set_id' => [
                Rule::requiredIf(fn () => $team->tokenCan('*')),
                'integer',
                Rule::exists('social_sets', 'id')->where('team_id', $team->id)->whereNull('deleted_at'),
            ],
            'platform' => ['required', Rule::enum(Platform::class)],
            'body' => ['nullable', 'string'],
            'media' => ['nullable', 'array'],
            'media.*' => ['url:http,https'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            ...$this->listRules($team),
        ];
    }
}
