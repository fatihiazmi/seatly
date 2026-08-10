<?php

use App\Enums\HoldStatus;
use App\Enums\SeatType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOGICAL_ERD.md §2.4 — holds and hold_items. Migration sequencing step 8.
 *
 * The two composite unique keys are not decoration. `(id, showtime_id)` is what
 * lets `showtime_seats` carry a composite FK to its owning hold, so an inventory
 * row can never point at a hold belonging to a different showtime.
 * `(id, user_id, showtime_id)` does the same job for `bookings` in Phase 5.
 *
 * Constraint triggers are deferred to commit on purpose: a hold and its items
 * are written across several statements inside one transaction, so any check
 * that compares the two is only meaningful once the transaction is complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('showtime_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default(HoldStatus::Active->value);
            $table->smallInteger('seat_count');
            $table->bigInteger('total_minor');
            $table->char('currency', 3);
            $table->timestampTz('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestampTz('expires_at');
            $table->timestampTz('closed_at')->nullable();
            $table->string('release_reason', 32)->nullable();
            $table->boolean('accessible_acknowledged')->default(false);
            $table->timestampTz('accessible_acknowledged_at')->nullable();
            $table->string('accessibility_policy_version', 32)->nullable();

            $table->unique(['id', 'showtime_id']);
            $table->unique(['id', 'user_id', 'showtime_id']);

            // LOGICAL_ERD.md §4.
            $table->index(['user_id', 'created_at']);
            $table->index(['showtime_id', 'status']);
        });

        DB::statement('CREATE INDEX holds_expiry_sweep_index ON holds (expires_at, id) '."WHERE status = 'ACTIVE'");

        $statuses = implode(', ', array_map(fn (string $v): string => "'{$v}'", HoldStatus::values()));

        DB::statement("ALTER TABLE holds ADD CONSTRAINT holds_status_valid CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE holds ADD CONSTRAINT holds_seat_count_within_limit CHECK (seat_count BETWEEN 1 AND 8)');
        DB::statement('ALTER TABLE holds ADD CONSTRAINT holds_total_not_negative CHECK (total_minor >= 0)');

        // RULE-012/PP-03/FR-014. Five minutes exactly, and no extension is
        // representable: changing expires_at without changing created_at fails.
        DB::statement(
            "ALTER TABLE holds ADD CONSTRAINT holds_expiry_is_five_minutes
             CHECK (expires_at = created_at + interval '5 minutes')"
        );

        // §6.1: ACTIVE is the only non-terminal state, and a terminal hold has
        // been closed at a known instant.
        DB::statement(<<<'SQL'
            ALTER TABLE holds ADD CONSTRAINT holds_closure_matches_status CHECK (
                (status = 'ACTIVE' AND closed_at IS NULL AND release_reason IS NULL)
             OR (status <> 'ACTIVE' AND closed_at IS NOT NULL)
            )
        SQL);

        // PP-10: the acknowledgement is all three columns or none of them. A
        // flag without a timestamp cannot evidence when it was given.
        DB::statement(<<<'SQL'
            ALTER TABLE holds ADD CONSTRAINT holds_acknowledgement_is_complete CHECK (
                (accessible_acknowledged
                    AND accessible_acknowledged_at IS NOT NULL
                    AND accessibility_policy_version IS NOT NULL)
             OR (NOT accessible_acknowledged
                    AND accessible_acknowledged_at IS NULL
                    AND accessibility_policy_version IS NULL)
            )
        SQL);

        Schema::create('hold_items', function (Blueprint $table) {
            // No single-column FKs here. The two composite keys below already
            // guarantee the hold, the showtime and the seat exist and agree
            // with each other; adding the narrow ones as well would enforce a
            // weaker version of the same thing twice.
            $table->unsignedBigInteger('hold_id');
            $table->unsignedBigInteger('showtime_id');
            $table->unsignedBigInteger('seat_id');
            $table->string('seat_label_snapshot', 16);
            $table->string('row_label_snapshot', 8);
            $table->string('seat_number_snapshot', 8);
            $table->string('seat_type_snapshot', 16);
            $table->bigInteger('price_minor');
            $table->char('currency', 3);

            $table->primary(['hold_id', 'seat_id']);
        });

        // The composite FKs LOGICAL_ERD.md §2.4 requires: an item cannot name a
        // hold from another showtime, nor a seat outside that showtime's
        // materialized inventory.
        DB::statement(
            'ALTER TABLE hold_items ADD CONSTRAINT hold_items_hold_showtime_foreign
             FOREIGN KEY (hold_id, showtime_id) REFERENCES holds (id, showtime_id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE hold_items ADD CONSTRAINT hold_items_inventory_foreign
             FOREIGN KEY (showtime_id, seat_id) REFERENCES showtime_seats (showtime_id, seat_id)'
        );

        $seatTypes = implode(', ', array_map(fn (string $v): string => "'{$v}'", SeatType::values()));

        DB::statement('ALTER TABLE hold_items ADD CONSTRAINT hold_items_price_not_negative CHECK (price_minor >= 0)');
        DB::statement("ALTER TABLE hold_items ADD CONSTRAINT hold_items_seat_type_valid CHECK (seat_type_snapshot IN ({$seatTypes}))");

        $this->createIntegrityTrigger();
    }

    /**
     * The deferred integrity check LOGICAL_ERD.md §2.4 specifies.
     *
     * It runs once per transaction, at commit, because a hold and its items are
     * written by separate statements and no intermediate state is meaningful.
     * Everything it asserts is also enforced by the acquisition action; this is
     * the backstop for anything that reaches the table by another route.
     */
    private function createIntegrityTrigger(): void
    {
        // CREATE OR REPLACE: migrate:fresh drops tables and their triggers but
        // leaves standalone functions behind, so a plain CREATE FUNCTION passes
        // on the first suite run and collides on the second.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION seatly_check_hold_integrity(target_hold_id BIGINT) RETURNS void AS $$
            DECLARE
                hold_row        holds%ROWTYPE;
                item_count      INTEGER;
                item_total      BIGINT;
                other_currency  INTEGER;
                other_showtime  INTEGER;
                orphan_companion INTEGER;
                undeclared      INTEGER;
            BEGIN
                SELECT * INTO hold_row FROM holds WHERE id = target_hold_id;

                IF NOT FOUND THEN
                    -- The hold itself was deleted; its items cascaded with it.
                    RETURN;
                END IF;

                SELECT count(*), COALESCE(sum(price_minor), 0)
                  INTO item_count, item_total
                  FROM hold_items WHERE hold_id = target_hold_id;

                IF item_count <> hold_row.seat_count THEN
                    RAISE EXCEPTION
                        'hold % has % items but seat_count %', target_hold_id, item_count, hold_row.seat_count;
                END IF;

                IF item_total <> hold_row.total_minor THEN
                    RAISE EXCEPTION
                        'hold % items total % but total_minor %', target_hold_id, item_total, hold_row.total_minor;
                END IF;

                SELECT count(*) INTO other_currency
                  FROM hold_items WHERE hold_id = target_hold_id AND currency <> hold_row.currency;

                IF other_currency > 0 THEN
                    RAISE EXCEPTION 'hold % mixes currencies', target_hold_id;
                END IF;

                SELECT count(*) INTO other_showtime
                  FROM hold_items WHERE hold_id = target_hold_id AND showtime_id <> hold_row.showtime_id;

                IF other_showtime > 0 THEN
                    RAISE EXCEPTION 'hold % spans more than one showtime', target_hold_id;
                END IF;

                -- MVP_POLICY_BASELINE.md §1: every companion seat held must have
                -- its linked accessible seat in the same hold.
                SELECT count(*) INTO orphan_companion
                  FROM hold_items hi
                  JOIN seat_companion_links l ON l.companion_seat_id = hi.seat_id
                 WHERE hi.hold_id = target_hold_id
                   AND NOT EXISTS (
                       SELECT 1 FROM hold_items sib
                        WHERE sib.hold_id = target_hold_id
                          AND sib.seat_id = l.accessible_seat_id
                   );

                IF orphan_companion > 0 THEN
                    RAISE EXCEPTION 'hold % holds a companion seat without its accessible seat', target_hold_id;
                END IF;

                -- PP-10: an accessible seat requires the declaration.
                SELECT count(*) INTO undeclared
                  FROM hold_items
                 WHERE hold_id = target_hold_id
                   AND seat_type_snapshot = 'ACCESSIBLE'
                   AND NOT hold_row.accessible_acknowledged;

                IF undeclared > 0 THEN
                    RAISE EXCEPTION 'hold % holds an accessible seat without the declaration', target_hold_id;
                END IF;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // Two thin trigger wrappers rather than one shared trigger function: the
        // hold's ID is `id` on `holds` and `hold_id` on `hold_items`, and plpgsql
        // resolves NEW's fields against the actual table, so a single function
        // referencing both names fails at runtime on whichever table lacks one.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION seatly_hold_items_integrity() RETURNS trigger AS $$
            BEGIN
                PERFORM seatly_check_hold_integrity(COALESCE(NEW.hold_id, OLD.hold_id));
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION seatly_holds_integrity() RETURNS trigger AS $$
            BEGIN
                PERFORM seatly_check_hold_integrity(NEW.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(
            'CREATE CONSTRAINT TRIGGER hold_items_integrity
             AFTER INSERT OR UPDATE OR DELETE ON hold_items
             DEFERRABLE INITIALLY DEFERRED
             FOR EACH ROW EXECUTE FUNCTION seatly_hold_items_integrity()'
        );

        DB::statement(
            'CREATE CONSTRAINT TRIGGER holds_integrity
             AFTER INSERT OR UPDATE ON holds
             DEFERRABLE INITIALLY DEFERRED
             FOR EACH ROW EXECUTE FUNCTION seatly_holds_integrity()'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('hold_items');
        Schema::dropIfExists('holds');
        DB::statement('DROP FUNCTION IF EXISTS seatly_holds_integrity()');
        DB::statement('DROP FUNCTION IF EXISTS seatly_hold_items_integrity()');
        DB::statement('DROP FUNCTION IF EXISTS seatly_check_hold_integrity(BIGINT)');
    }
};
