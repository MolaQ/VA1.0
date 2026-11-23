<?php

namespace App\Http\Requests\Api;

use App\Enums\AnalysisStatus;
use App\Models\Game;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAnalysisRequest extends FormRequest
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
            'status' => ['required', Rule::enum(AnalysisStatus::class)],
            'progress' => ['nullable', 'integer', 'between:0,100'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
