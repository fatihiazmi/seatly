<?php

use App\Enums\SeatType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOGICAL_ERD.md §2.2 — seats. Migration sequencing step 4.
 *
 * sort_order is the canonical display and locking order within a hall. The
 * transactional lock order in Phase 3 still uses the immutable seat id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seats', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('hall_id')->constrained()->restrictOnDelete();
            $table->string('row_label', 16);
            $table->string('seat_number', 16);
            $table->string('display_label', 40);
            $table->string('seat_type', 16)->default(SeatType::Standard->value);
            $table->integer('sort_order');
            $table->smallInteger('x_position')->nullable();
            $table->smallInteger('y_position')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['hall_id', 'row_label', 'seat_number']);
            $table->unique(['hall_id', 'sort_order']);

            // Seat-map read path. LOGICAL_ERD.md §4.
            $table->index(['hall_id', 'is_active', 'sort_order']);
        });

        $types = implode(', ', array_map(
            fn (string $value): string => "'{$value}'",
            SeatType::values()
        ));

        DB::statement("ALTER TABLE seats ADD CONSTRAINT seats_seat_type_valid CHECK (seat_type IN ({$types}))");
        DB::statement('ALTER TABLE seats ADD CONSTRAINT seats_sort_order_not_negative CHECK (sort_order >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('seats');
    }
};
