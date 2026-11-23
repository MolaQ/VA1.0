<?php

namespace Database\Factories;

use App\Enums\Position;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'club_id' => fn (array $attributes) => Team::query()->whereKey($attributes['team_id'])->value('club_id'),
            'first_name' => fake()->firstNameMale(),
            'last_name' => fake()->lastName(),
            'number' => fake()->unique()->numberBetween(1, 99),
            'position' => fake()->randomElement(Position::cases()),
            'is_active' => true,
        ];
    }
}
