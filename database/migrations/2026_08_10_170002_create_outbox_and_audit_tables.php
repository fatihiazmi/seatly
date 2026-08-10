<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOGICAL_ERD.md §2.9 — outbox_events and audit_events. Migration sequencing
 * step 14, minus `idempotency_keys`, which arrives with `H3-03`.
 *
 * Both are written inside the same transaction as the domain mutation they
 * describe. That is the whole point: an event that survives a rolled-back hold
 * would describe something that never happened, and a hold with no event would
 * be invisible to everything downstream.
 *
 * The relay that publishes outbox rows is Phase 5. Until then this table is
 * write-only, and that is intentional — the durability guarantee is what Phase 3
 * needs, not the delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('aggregate_type', 64);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type', 128);
            $table->jsonb('payload');
            $table->uuid('correlation_id');
            $table->timestampTz('occurred_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestampTz('available_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestampTz('processed_at')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
        });

        DB::statement('ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_attempts_not_negative CHECK (attempt_count >= 0)');

        // The relay's only query: unprocessed and due, oldest first.
        DB::statement(
            'CREATE INDEX outbox_events_pending_index ON outbox_events (available_at, occurred_at) WHERE processed_at IS NULL'
        );

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->timestampTz('occurred_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->string('actor_type', 16);
            // A deleted user must not take the audit trail with them.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 128);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->uuid('correlation_id');
            $table->string('result', 32);
            $table->string('reason_code', 64)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));

            $table->index(['correlation_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id', 'occurred_at']);
        });

        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_events_actor_type_valid CHECK (actor_type IN ('USER', 'SYSTEM', 'PROVIDER'))");

        // Append-only in the schema, not only by convention. The privilege grant
        // LOGICAL_ERD.md §2.9 asks for belongs to deployment, where the
        // application connects as a role that is not the table owner; this rule
        // holds regardless of who connects.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION seatly_audit_is_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(
            'CREATE TRIGGER audit_events_append_only
             BEFORE UPDATE OR DELETE ON audit_events
             FOR EACH ROW EXECUTE FUNCTION seatly_audit_is_append_only()'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('outbox_events');
        DB::statement('DROP FUNCTION IF EXISTS seatly_audit_is_append_only()');
    }
};
