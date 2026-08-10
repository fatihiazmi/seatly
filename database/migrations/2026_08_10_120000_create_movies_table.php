<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOGICAL_ERD.md §2.2 — movies. Migration sequencing step 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movies', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('title');
            $table->text('synopsis')->nullable();
            $table->smallInteger('runtime_minutes');
            $table->string('classification')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE movies ADD CONSTRAINT movies_runtime_minutes_positive CHECK (runtime_minutes > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('movies');
    }
};
