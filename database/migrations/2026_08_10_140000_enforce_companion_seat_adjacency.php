<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-018 (`D21`) — define what "adjacent" means for a companion seat and
 * enforce it.
 *
 * `LOGICAL_ERD.md` §2.2 required the pair to be "valid/adjacent according to the
 * hall layout" without defining adjacency. It is now: same `row_label`, and
 * `sort_order` differing by exactly one. Adjacency is symmetric — the companion
 * may sit on either side.
 *
 * Known limitation, recorded in `ADR-018`: two seats either side of an aisle are
 * consecutive in `sort_order`, so such a pair is accepted. No column records
 * aisles.
 *
 * This replaces the function created alongside the table rather than editing
 * that migration, which is already published. Both versions are written out in
 * full because `unprepared()` takes a literal string.
 */
return new class extends Migration
{
    public function up(): void
    {
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

                IF accessible_seat.row_label <> companion_seat.row_label THEN
                    RAISE EXCEPTION
                        'seat_companion_links: seats % and % are in different rows (% and %); a pair must share a row',
                        accessible_seat.id, companion_seat.id,
                        accessible_seat.row_label, companion_seat.row_label;
                END IF;

                IF abs(accessible_seat.sort_order - companion_seat.sort_order) <> 1 THEN
                    RAISE EXCEPTION
                        'seat_companion_links: seats % and % are not adjacent (sort_order % and %); a pair must be consecutive',
                        accessible_seat.id, companion_seat.id,
                        accessible_seat.sort_order, companion_seat.sort_order;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
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
    }
};
