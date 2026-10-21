<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Team;
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
        /** @var Team $team */
        $team = $this->user();

        return [
            'status' => ['nullable', Rule::enum(PostStatus::class)],
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'social_set_id' => ['nullable', 'integer', Rule::exists('social_sets', 'id')->where('team_id', $team->id)->whereNull('deleted_at')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
