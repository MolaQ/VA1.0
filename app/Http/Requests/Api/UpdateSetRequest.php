<?php

namespace App\Http\Requests\Api;

use App\Models\Game;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Game $game */
        $game = $this->route('game');

        return $this->user()?->tokenCan('analysis:write') === true
            && $this->user()->can('code', $game);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'score_us' => ['sometimes', 'integer', 'between:0,99'],
            'score_them' => ['sometimes', 'integer', 'between:0,99'],
            'video_start' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'our_side' => ['sometimes', 'nullable', 'in:near,far'],
            'lineup' => ['sometimes', 'array', 'max:14'],
            'lineup.*.player_id' => ['nullable', 'integer', 'required_without:lineup.*.player_number'],
            'lineup.*.player_number' => ['nullable', 'integer', 'between:0,99'],
            'lineup.*.rotation_position' => ['nullable', 'integer', 'between:1,6'],
            'lineup.*.is_substitute' => ['sometimes', 'boolean'],
        ];
    }
}
