<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LOGICAL_ERD.md §2.3, migration sequencing step 11 — the hold half.
 *
 * `showtime_seats` was created at step 7 with `hold_id` and `booking_id` as
 * plain columns, because their owner tables did not exist yet. Now that `holds`
 * does, the composite FK can be added: an inventory row may only point at a hold
 * for the *same showtime*.
 *
 * The booking half waits for Phase 5, when `bookings` exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE showtime_seats ADD CONSTRAINT showtime_seats_hold_foreign
             FOREIGN KEY (hold_id, showtime_id) REFERENCES holds (id, showtime_id)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE showtime_seats DROP CONSTRAINT IF EXISTS showtime_seats_hold_foreign');
    }
};
