<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOGICAL_ERD.md §2.2 — seat_companion_links. Migration sequencing step 5.
 *
 * A pair contains exactly one accessible seat and zero or one companion seat,
 * and a seat cannot participate in two pairs. The two unique keys carry that.
 *
 * The remaining rules compare columns on the referenced seat rows, which a
 * PostgreSQL CHECK cannot do, so they live in a deferred constraint trigger.
 *
 * ADR-012; PP-10; MVP_POLICY_BASELINE.md §1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_companion_links', function (Blueprint $table) {
            // The accessible seat is the primary key, so it can hold at most one pair.
            $table->foreignId('accessible_seat_id')->primary()->constrained('seats')->restrictOnDelete();
            $table->foreignId('companion_seat_id')->unique()->constrained('seats')->restrictOnDelete();
            $table->timestampTz('created_at')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE seat_companion_links
            ADD CONSTRAINT seat_companion_links_distinct_seats
            CHECK (accessible_seat_id <> companion_seat_id)
        SQL);

        // CREATE OR REPLACE, not CREATE: migrate:fresh drops tables but leaves
        // standalone functions behind, so a plain CREATE collides on the second
        // run of the test suite.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION seat_companion_links_validate() RETURNS trigger AS $$
            DECLARE
                accessible_seat seats%ROWTYPE;
                companion_seat  seats%ROWTYPE;
            BEGIN
                SELECT * INTO accessible_seat FROM seats WHERE id = NEW.accessible_seat_id;
                SELECT * INTO companion_seat  FROM seats WHERE id = NEW.companion_seat_id;

                IF accessible_seat.hall_id <> companion_seat.hall_id THEN
                    RAISE EXCEPTION
                        'seat_companion_links: seats % and % belong to different halls (% and %)',
                        accessible_seat.id, companion_seat.id,
                        accessible_seat.hall_id, companion_seat.hall_id;
                END IF;

                IF accessible_seat.seat_type <> 'ACCESSIBLE' THEN
                    RAISE EXCEPTION
                        'seat_companion_links: seat % is %, the accessible side must be ACCESSIBLE',
                        accessible_seat.id, accessible_seat.seat_type;
                END IF;

                IF companion_seat.seat_type <> 'COMPANION' THEN
                    RAISE EXCEPTION
                        'seat_companion_links: seat % is %, the companion side must be COMPANION',
                        companion_seat.id, companion_seat.seat_type;
                END IF;

                IF NOT accessible_seat.is_active OR NOT companion_seat.is_active THEN
                    RAISE EXCEPTION
                        'seat_companion_links: both seats must be active (% active=%, % active=%)',
                        accessible_seat.id, accessible_seat.is_active,
                        companion_seat.id, companion_seat.is_active;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER seat_companion_links_validate_trigger
            AFTER INSERT OR UPDATE ON seat_companion_links
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION seat_companion_links_validate();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS seat_companion_links_validate_trigger ON seat_companion_links');
        DB::unprepared('DROP FUNCTION IF EXISTS seat_companion_links_validate()');
        Schema::dropIfExists('seat_companion_links');
    }
};
