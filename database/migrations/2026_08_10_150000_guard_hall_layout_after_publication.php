<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-019 (`C2-03`) — structural integrity after publication.
 *
 * A hall's sellable seat set, seat identity, ordering and classification are
 * frozen while an **upcoming** published showtime references it. Descriptive
 * attributes stay editable: `hold_items` and `booking_items` snapshot the seat
 * label and type they sold, so correcting a label cannot rewrite history.
 *
 * The freeze protects the materialized `showtime_seats` rows, which are written
 * once at publication (`ADR-005`) and would otherwise end up describing a hall
 * that no longer exists.
 *
 * Enforced in the database rather than in an Action, because a direct SQL write
 * must not be able to corrupt published inventory. `NFR-010`.
 *
 * "Frozen" uses the same derived on-sale rule as the rest of the system — there
 * is no stored `ENDED` state (`RC-03`), so this asks PostgreSQL for the time
 * inside whatever transaction is writing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hall_layout_is_frozen(target_hall_id BIGINT) RETURNS boolean AS $$
                SELECT EXISTS (
                    SELECT 1 FROM showtimes
                    WHERE hall_id = target_hall_id
                      AND status = 'PUBLISHED'
                      AND transaction_timestamp() < starts_at
                );
            $$ LANGUAGE sql STABLE;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION seats_structural_guard() RETURNS trigger AS $$
            DECLARE
                subject_hall_id BIGINT;
            BEGIN
                subject_hall_id := COALESCE(NEW.hall_id, OLD.hall_id);

                IF TG_OP = 'INSERT' THEN
                    IF hall_layout_is_frozen(subject_hall_id) THEN
                        RAISE EXCEPTION
                            'hall %: a seat cannot be added while an upcoming published showtime references this hall',
                            subject_hall_id
                            USING ERRCODE = 'raise_exception';
                    END IF;
                    RETURN NEW;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    IF hall_layout_is_frozen(subject_hall_id) THEN
                        RAISE EXCEPTION
                            'hall %: seat % cannot be deleted while an upcoming published showtime references this hall',
                            subject_hall_id, OLD.id
                            USING ERRCODE = 'raise_exception';
                    END IF;
                    RETURN OLD;
                END IF;

                -- UPDATE. Only the frozen categories are refused; display_label,
                -- x_position and y_position stay editable.
                IF (OLD.hall_id     IS DISTINCT FROM NEW.hall_id
                 OR OLD.row_label   IS DISTINCT FROM NEW.row_label
                 OR OLD.seat_number IS DISTINCT FROM NEW.seat_number
                 OR OLD.sort_order  IS DISTINCT FROM NEW.sort_order
                 OR OLD.seat_type   IS DISTINCT FROM NEW.seat_type
                 OR OLD.is_active   IS DISTINCT FROM NEW.is_active)
                THEN
                    IF hall_layout_is_frozen(OLD.hall_id)
                       OR hall_layout_is_frozen(NEW.hall_id) THEN
                        RAISE EXCEPTION
                            'hall %: seat % identity, ordering, classification and availability are frozen while an upcoming published showtime references this hall',
                            OLD.hall_id, OLD.id
                            USING ERRCODE = 'raise_exception';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER seats_structural_guard_trigger
            BEFORE INSERT OR UPDATE OR DELETE ON seats
            FOR EACH ROW EXECUTE FUNCTION seats_structural_guard();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION halls_structural_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.is_active IS DISTINCT FROM NEW.is_active
                   AND hall_layout_is_frozen(OLD.id) THEN
                    RAISE EXCEPTION
                        'hall %: cannot change activation while an upcoming published showtime references this hall',
                        OLD.id
                        USING ERRCODE = 'raise_exception';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER halls_structural_guard_trigger
            BEFORE UPDATE ON halls
            FOR EACH ROW EXECUTE FUNCTION halls_structural_guard();
        SQL);

        // layout_version answers "has this layout changed since?". It moves only
        // on a frozen-category change, which by definition can only happen while
        // the hall is not frozen. It is not a version graph: prior layouts are
        // not retained. ADR-019.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION seats_bump_layout_version() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                   AND NOT (OLD.hall_id     IS DISTINCT FROM NEW.hall_id
                         OR OLD.row_label   IS DISTINCT FROM NEW.row_label
                         OR OLD.seat_number IS DISTINCT FROM NEW.seat_number
                         OR OLD.sort_order  IS DISTINCT FROM NEW.sort_order
                         OR OLD.seat_type   IS DISTINCT FROM NEW.seat_type
                         OR OLD.is_active   IS DISTINCT FROM NEW.is_active)
                THEN
                    RETURN NULL;
                END IF;

                UPDATE halls
                   SET layout_version = layout_version + 1,
                       updated_at = transaction_timestamp()
                 WHERE id IN (COALESCE(NEW.hall_id, OLD.hall_id), COALESCE(OLD.hall_id, NEW.hall_id));

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER seats_bump_layout_version_trigger
            AFTER INSERT OR UPDATE OR DELETE ON seats
            FOR EACH ROW EXECUTE FUNCTION seats_bump_layout_version();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS seats_bump_layout_version_trigger ON seats');
        DB::unprepared('DROP TRIGGER IF EXISTS seats_structural_guard_trigger ON seats');
        DB::unprepared('DROP TRIGGER IF EXISTS halls_structural_guard_trigger ON halls');
        DB::unprepared('DROP FUNCTION IF EXISTS seats_bump_layout_version()');
        DB::unprepared('DROP FUNCTION IF EXISTS seats_structural_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS halls_structural_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS hall_layout_is_frozen(BIGINT)');
    }
};
