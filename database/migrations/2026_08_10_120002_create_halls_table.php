<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOGICAL_ERD.md §2.2 — halls. Migration sequencing step 4.
 *
 * Structural changes are prohibited once a published showtime references the
 * hall; deactivation is preferred over deletion, so the venue FK restricts.
 * NFR-010.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('halls', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('venue_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->integer('layout_version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['venue_id', 'code']);
        });

        DB::statement('ALTER TABLE halls ADD CONSTRAINT halls_layout_version_positive CHECK (layout_version > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('halls');
    }
};
