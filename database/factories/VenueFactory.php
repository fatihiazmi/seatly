<?php

namespace Database\Factories;

use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Cineplex',
            // PP-11: the approved venue timezone baseline.
            'timezone' => 'Asia/Kuala_Lumpur',
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postcode' => fake()->numerify('#####'),
            'state' => fake()->randomElement(['Selangor', 'Johor', 'Pulau Pinang']),
            'is_active' => true,
        ];
    }
}
