<?php

namespace Database\Seeders;

use App\Actions\Showtimes\PublishShowtime;
use App\Enums\SeatType;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\Seat;
use App\Models\SeatCompanionLink;
use App\Models\Showtime;
use App\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Migration sequencing step 16 — seed a venue, hall and layout, then rehearse
 * publish-time inventory materialization.
 *
 * This is a development fixture for inspecting real data, not test data:
 * the suite builds its own via factories. Run with
 * `php artisan db:seed --class=CatalogueSeeder`.
 */
class CatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $venue = Venue::firstOrCreate(
            ['name' => 'Seatly Mid Valley'],
            [
                'timezone' => 'Asia/Kuala_Lumpur',
                'address_line_1' => 'Lingkaran Syed Putra',
                'city' => 'Kuala Lumpur',
                'postcode' => '59200',
                'state' => 'Wilayah Persekutuan',
                'is_active' => true,
            ]
        );

        $halls = collect(['HALL-1', 'HALL-2'])->map(fn (string $code): Hall => Hall::firstOrCreate(
            ['venue_id' => $venue->id, 'code' => $code],
            ['name' => str_replace('HALL-', 'Hall ', $code), 'layout_version' => 1, 'is_active' => true]
        ));

        $halls->each(fn (Hall $hall) => $this->layOutSeats($hall));

        $movies = collect([
            ['title' => 'Sinar Terakhir', 'runtime_minutes' => 118, 'classification' => 'P13'],
            ['title' => 'The Quiet Harbour', 'runtime_minutes' => 96, 'classification' => 'U'],
            ['title' => 'Kolam Dalam', 'runtime_minutes' => 134, 'classification' => '18'],
        ])->map(fn (array $attributes): Movie => Movie::firstOrCreate(
            ['title' => $attributes['title']],
            $attributes + ['synopsis' => 'Development fixture.', 'is_active' => true]
        ));

        $this->scheduleShowtimes($halls->first(), $movies);
    }

    /**
     * Six rows of ten, with row F carrying two accessible seats, each paired
     * with the companion seat beside it.
     */
    private function layOutSeats(Hall $hall): void
    {
        if ($hall->seats()->exists()) {
            return;
        }

        $order = 0;

        foreach (range('A', 'F') as $row) {
            foreach (range(1, 10) as $number) {
                $isAccessible = $row === 'F' && in_array($number, [1, 9], true);
                $isCompanion = $row === 'F' && in_array($number, [2, 10], true);

                Seat::create([
                    'hall_id' => $hall->id,
                    'row_label' => $row,
                    'seat_number' => (string) $number,
                    'display_label' => $row.$number,
                    'seat_type' => match (true) {
                        $isAccessible => SeatType::Accessible,
                        $isCompanion => SeatType::Companion,
                        default => SeatType::Standard,
                    },
                    'sort_order' => $order++,
                    'is_active' => true,
                ]);
            }
        }

        $seats = $hall->seats()->get()->keyBy('display_label');

        foreach ([['F1', 'F2'], ['F9', 'F10']] as [$accessible, $companion]) {
            SeatCompanionLink::create([
                'accessible_seat_id' => $seats[$accessible]->id,
                'companion_seat_id' => $seats[$companion]->id,
            ]);
        }

        // The pairing trigger is deferred, so force it here rather than
        // discovering a bad fixture at the end of the outer transaction.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /**
     * @param  Collection<int, Movie>  $movies
     */
    private function scheduleShowtimes(Hall $hall, $movies): void
    {
        if ($hall->showtimes()->exists()) {
            return;
        }

        $slot = now()->addDay()->startOfDay()->addHours(13);

        foreach ($movies as $index => $movie) {
            $showtime = Showtime::create([
                'movie_id' => $movie->id,
                'hall_id' => $hall->id,
                'starts_at' => $slot,
                'ends_at' => $slot->copy()->addMinutes($movie->runtime_minutes + 20),
                'price_minor' => 1850,
                'currency' => 'MYR',
                'cancellation_cutoff_minutes' => 120,
            ]);

            // Leave the last one as a draft so both states are visible.
            if ($index < $movies->count() - 1) {
                app(PublishShowtime::class)->handle($showtime);
            }

            $slot = $slot->copy()->addMinutes($movie->runtime_minutes + 50);
        }
    }
}
