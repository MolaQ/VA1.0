<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'name' => fake()->randomElement(['Seniorzy', 'Juniorzy', 'Kadeci', 'Młodzicy']),
            'season' => '2026/2027',
        ];
    }
}
