<?php

namespace Database\Factories;

use App\Enums\ActionSource;
use App\Enums\Grade;
use App\Enums\Skill;
use App\Models\Game;
use App\Models\GameAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameAction>
 */
class GameActionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'club_id' => fn (array $attributes) => Game::query()->whereKey($attributes['game_id'])->value('club_id'),
            'skill' => fake()->randomElement(Skill::cases()),
            'grade' => fake()->randomElement(Grade::cases()),
            'video_time' => fake()->randomFloat(2, 0, 7200),
            'source' => ActionSource::Manual,
        ];
    }
}
