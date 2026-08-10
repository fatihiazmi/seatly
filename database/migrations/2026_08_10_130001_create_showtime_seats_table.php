<?php

use App\Enums\SeatInventoryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOGICAL_ERD.md §2.3 — showtime_seats, the authoritative mutable inventory.
 * Migration sequencing step 7: owner id columns now, owner foreign keys later.
 *
 * The composite ownership FKs — (hold_id, showtime_id) and
 * (booking_id, showtime_id) — are step 11, once holds and bookings exist in
 * Phase 3 and Phase 5. The state-dependent linkage CHECK is added here rather
 * than at step 12 because it compares columns within one row and has no
 * dependency on those tables; adding it now makes every C2-02 row provably
 * clean instead of trusting the writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showtime_seats', function (Blueprint $table) {
            $table->foreignId('showtime_id')->constrained()->restrictOnDelete();
            $table->foreignId('seat_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default(SeatInventoryStatus::Available->value);
            $table->unsignedBigInteger('hold_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->timestampTz('hold_expires_at')->nullable();
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->integer('version')->default(1);
            $table->string('blocked_reason_code', 64)->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->primary(['showtime_id', 'seat_id']);

            // Contention and worker paths. LOGICAL_ERD.md §4.
            $table->index(['showtime_id', 'status', 'seat_id']);
        });

        DB::statement('CREATE INDEX showtime_seats_hold_id_index ON showtime_seats (hold_id) WHERE hold_id IS NOT NULL');
        DB::statement('CREATE INDEX showtime_seats_booking_id_index ON showtime_seats (booking_id) WHERE booking_id IS NOT NULL');
        DB::statement(
            "CREATE INDEX showtime_seats_expiry_sweep_index
             ON showtime_seats (hold_expires_at, showtime_id, seat_id) WHERE status = 'HELD'"
        );

        $statuses = implode(', ', array_map(
            fn (string $value): string => "'{$value}'",
            SeatInventoryStatus::values()
        ));

        DB::statement("ALTER TABLE showtime_seats ADD CONSTRAINT showtime_seats_status_valid CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE showtime_seats ADD CONSTRAINT showtime_seats_price_not_negative CHECK (price_minor >= 0)');
        DB::statement('ALTER TABLE showtime_seats ADD CONSTRAINT showtime_seats_version_positive CHECK (version > 0)');

        // Each state names exactly one current owner, or none.
        // DOMAIN_STATE_MODELS.md §5.1, LOGICAL_ERD.md §2.3.
        DB::statement(<<<'SQL'
            ALTER TABLE showtime_seats ADD CONSTRAINT showtime_seats_linkage_matches_status CHECK (
                (status = 'AVAILABLE'
                    AND hold_id IS NULL AND booking_id IS NULL
                    AND hold_expires_at IS NULL AND blocked_reason_code IS NULL)
             OR (status = 'HELD'
                    AND hold_id IS NOT NULL AND booking_id IS NULL
                    AND hold_expires_at IS NOT NULL AND blocked_reason_code IS NULL)
             OR (status = 'CONFIRMED'
                    AND hold_id IS NULL AND booking_id IS NOT NULL
                    AND hold_expires_at IS NULL AND blocked_reason_code IS NULL)
             OR (status = 'BLOCKED'
                    AND hold_id IS NULL AND booking_id IS NULL
                    AND hold_expires_at IS NULL AND blocked_reason_code IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('showtime_seats');
    }
};
