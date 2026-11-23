<?php

namespace App\Http\Requests\Api;

use App\Enums\Grade;
use App\Enums\Skill;
use App\Models\Game;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActionsRequest extends FormRequest
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
            'actions' => ['required', 'array', 'min:1', 'max:1000'],
            'actions.*.external_id' => ['nullable', 'string', 'max:100', 'distinct'],
            'actions.*.player_id' => ['nullable', 'integer'],
            'actions.*.player_number' => ['nullable', 'integer', 'between:0,99'],
            'actions.*.set' => ['nullable', 'integer', 'between:1,5'],
            'actions.*.rally' => ['nullable', 'integer', 'min:1', 'max:999'],
            'actions.*.skill' => ['required', Rule::enum(Skill::class)],
            'actions.*.grade' => ['required', Rule::enum(Grade::class)],
            'actions.*.video_time' => ['nullable', 'numeric', 'min:0', 'max:86400'],
            'actions.*.zone_from' => ['nullable', 'integer', 'between:1,9'],
            'actions.*.zone_to' => ['nullable', 'integer', 'between:1,9'],
            'actions.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            'actions.*.meta' => ['nullable', 'array'],
            'actions.*.needs_review' => ['nullable', 'boolean'],
            'actions.*.review_note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
