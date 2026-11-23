<?php

namespace Database\Factories;

use App\Enums\AnalysisStatus;
use App\Models\Game;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'club_id' => fn (array $attributes) => Team::query()->whereKey($attributes['team_id'])->value('club_id'),
            'opponent' => 'MKS '.fake()->city(),
            'played_at' => fake()->dateTimeBetween('-2 months', 'now'),
            'is_home' => fake()->boolean(),
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'analysis_status' => AnalysisStatus::Pending,
        ];
    }
}
