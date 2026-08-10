<?php

namespace Database\Factories;

use App\Enums\SeatType;
use App\Models\Hall;
use App\Models\Seat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seat>
 */
class SeatFactory extends Factory
{
    private const ROW_LABELS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const SEATS_PER_ROW = 20;

    /**
     * Seats are unique on (hall_id, row_label, seat_number) and on
     * (hall_id, sort_order), so both default to a running sequence.
     */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $position = self::$sequence++;
        $row = substr(self::ROW_LABELS, intdiv($position, self::SEATS_PER_ROW) % strlen(self::ROW_LABELS), 1);
        $number = ($position % self::SEATS_PER_ROW) + 1;

        return [
            'hall_id' => Hall::factory(),
            'row_label' => $row,
            'seat_number' => (string) $number,
            'display_label' => $row.$number,
            'seat_type' => SeatType::Standard,
            'sort_order' => $position,
            'is_active' => true,
        ];
    }

    public function accessible(): static
    {
        return $this->state(fn (): array => ['seat_type' => SeatType::Accessible]);
    }

    public function companion(): static
    {
        return $this->state(fn (): array => ['seat_type' => SeatType::Companion]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
