<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOGICAL_ERD.md §2.9 — idempotency_keys. Migration sequencing step 14.
 *
 * The unique key is `(scope, principal_key, key_hash)`, and each part earns its
 * place: `scope` keeps a hold command and a payment command from colliding on
 * the same client key, `principal_key` keeps two customers who both send "1"
 * apart, and `key_hash` means a stolen database never yields a usable client
 * key. §14 names this unique constraint as a required backstop.
 *
 * Nothing here stores a name, an email, or a raw client key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64);
            // Non-PII namespace, e.g. "user:123" — never an email.
            $table->string('principal_key', 128);
            $table->char('key_hash', 64);
            $table->char('request_fingerprint', 64);
            $table->string('status', 16);
            $table->smallInteger('http_status')->nullable();
            $table->string('result_type', 64)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            // Minimal and already redacted: this is the response the client
            // would have received, not an internal dump.
            $table->jsonb('response_snapshot')->nullable();
            $table->timestampTz('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at');

            $table->unique(['scope', 'principal_key', 'key_hash']);
            // Retention sweep: 30 days, LOGICAL_ERD.md §2.9.
            $table->index('expires_at');
        });

        DB::statement(
            "ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_status_valid
             CHECK (status IN ('IN_PROGRESS', 'COMPLETED', 'FAILED'))"
        );

        // A settled record must carry the outcome it settled on, or it cannot
        // do the one job it exists for — answering the retry.
        DB::statement(<<<'SQL'
            ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_settled_records_carry_a_result CHECK (
                (status = 'IN_PROGRESS'
                    AND completed_at IS NULL AND http_status IS NULL AND response_snapshot IS NULL)
             OR (status <> 'IN_PROGRESS'
                    AND completed_at IS NOT NULL AND http_status IS NOT NULL AND response_snapshot IS NOT NULL)
            )
        SQL);

        DB::statement('ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_expire_after_creation CHECK (expires_at > created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
