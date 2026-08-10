<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOGICAL_ERD.md §2.2 — venues. Migration sequencing step 4.
 *
 * The venue timezone controls display and policy boundaries such as the
 * cancellation cutoff; instants are still persisted in UTC. PP-11, ADR-013.
 * IANA validation is an application concern — a CHECK cannot consult
 * pg_timezone_names, which is not immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('name');
            $table->string('timezone', 64)->default('Asia/Kuala_Lumpur');
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode', 16)->nullable();
            $table->string('state')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
