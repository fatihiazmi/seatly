<?php

namespace Database\Factories;

use App\Models\Hall;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hall>
 */
class HallFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'HALL-'.fake()->unique()->numberBetween(1, 100_000);

        return [
            'venue_id' => Venue::factory(),
            'code' => $code,
            'name' => $code,
            'layout_version' => 1,
            'is_active' => true,
        ];
    }
}
