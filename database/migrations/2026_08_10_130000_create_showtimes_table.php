<?php

use App\Enums\ShowtimeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOGICAL_ERD.md §2.3 — showtimes. Migration sequencing step 6.
 *
 * No ENDED status (RC-03): "still to come" is derived as
 * status = 'PUBLISHED' AND transaction_timestamp() < starts_at, so a finished
 * showtime cannot read as bookable because a lifecycle job failed to run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showtimes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('movie_id')->constrained()->restrictOnDelete();
            $table->foreignId('hall_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default(ShowtimeStatus::Draft->value);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('sales_open_at')->nullable();
            $table->timestampTz('sales_close_at')->nullable();
            $table->bigInteger('price_minor');
            $table->char('currency', 3)->default('MYR');
            $table->integer('cancellation_cutoff_minutes')->default(120);
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason_code', 64)->nullable();
            $table->integer('version')->default(1);
            $table->timestampsTz();

            // Browse and seat-map access paths. LOGICAL_ERD.md §4.
            $table->index(['status', 'starts_at']);
            $table->index(['hall_id', 'starts_at']);
        });

        DB::statement(
            "CREATE INDEX showtimes_movie_id_starts_at_published_index
             ON showtimes (movie_id, starts_at) WHERE status = 'PUBLISHED'"
        );

        $statuses = implode(', ', array_map(
            fn (string $value): string => "'{$value}'",
            ShowtimeStatus::values()
        ));

        DB::statement("ALTER TABLE showtimes ADD CONSTRAINT showtimes_status_valid CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE showtimes ADD CONSTRAINT showtimes_ends_after_start CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE showtimes ADD CONSTRAINT showtimes_price_not_negative CHECK (price_minor >= 0)');
        DB::statement("ALTER TABLE showtimes ADD CONSTRAINT showtimes_currency_iso_format CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE showtimes ADD CONSTRAINT showtimes_cutoff_not_negative CHECK (cancellation_cutoff_minutes >= 0)');
        DB::statement('ALTER TABLE showtimes ADD CONSTRAINT showtimes_version_positive CHECK (version > 0)');

        // State-dependent timestamps. DOMAIN_STATE_MODELS.md §4, LOGICAL_ERD.md §2.3.
        DB::statement(<<<'SQL'
            ALTER TABLE showtimes ADD CONSTRAINT showtimes_status_timestamps_consistent CHECK (
                (status = 'DRAFT'     AND published_at IS NULL     AND cancelled_at IS NULL)
             OR (status = 'PUBLISHED' AND published_at IS NOT NULL AND cancelled_at IS NULL)
             OR (status = 'CANCELLED' AND cancelled_at IS NOT NULL)
            )
        SQL);

        // Two published showtimes cannot overlap in one hall. Half-open '[)', so
        // one ending exactly as the next begins is not an overlap. Requires
        // btree_gist, installed by docker/postgres/init.
        DB::statement(<<<'SQL'
            ALTER TABLE showtimes ADD CONSTRAINT showtimes_no_overlap_per_hall
            EXCLUDE USING gist (
                hall_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            )
            WHERE (status = 'PUBLISHED')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('showtimes');
    }
};
