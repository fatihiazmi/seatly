<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-020 — extend the `ADR-019` freeze one level up.
 *
 * `venues.is_active` cannot change while any hall in the venue has an upcoming
 * published showtime, so a showtime on sale can never sit in a retired venue.
 * Descriptive venue attributes — name, address, timezone — stay editable.
 *
 * `FR-041`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION venue_has_frozen_hall(target_venue_id BIGINT) RETURNS boolean AS $$
                SELECT EXISTS (
                    SELECT 1
                    FROM showtimes s
                    JOIN halls h ON h.id = s.hall_id
                    WHERE h.venue_id = target_venue_id
                      AND s.status = 'PUBLISHED'
                      AND transaction_timestamp() < s.starts_at
                );
            $$ LANGUAGE sql STABLE;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION venues_structural_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.is_active IS DISTINCT FROM NEW.is_active
                   AND venue_has_frozen_hall(OLD.id) THEN
                    RAISE EXCEPTION
                        'venue %: cannot change activation while one of its halls has an upcoming published showtime',
                        OLD.id
                        USING ERRCODE = 'raise_exception';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER venues_structural_guard_trigger
            BEFORE UPDATE ON venues
            FOR EACH ROW EXECUTE FUNCTION venues_structural_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS venues_structural_guard_trigger ON venues');
        DB::unprepared('DROP FUNCTION IF EXISTS venues_structural_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS venue_has_frozen_hall(BIGINT)');
    }
};
