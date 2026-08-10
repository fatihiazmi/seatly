<?php

namespace Database\Factories;

use App\Enums\ShowtimeStatus;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\Showtime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Showtime>
 */
class ShowtimeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDays(fake()->numberBetween(1, 30))->startOfHour();

        return [
            'movie_id' => Movie::factory(),
            'hall_id' => Hall::factory(),
            'status' => ShowtimeStatus::Draft,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(fake()->numberBetween(90, 180)),
            'price_minor' => fake()->numberBetween(1200, 3500),
            'currency' => 'MYR',
            'cancellation_cutoff_minutes' => 120,
            'version' => 1,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ShowtimeStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => ShowtimeStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason_code' => 'OPERATIONAL',
        ]);
    }
}
