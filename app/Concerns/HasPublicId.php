<?php

namespace App\Concerns;

use Illuminate\Support\Str;

/**
 * Assigns the opaque external identifier every externally exposed record
 * carries alongside its internal BIGINT key. LOGICAL_ERD.md §1.
 *
 * The column also has a gen_random_uuid() default as the database-level
 * backstop; this fills the value in at insert time so the attribute is
 * populated on the model without a second round trip.
 *
 * Deliberately UUID v4, not v7: a v7 value encodes its creation timestamp, and
 * public_id is handed to unauthenticated clients.
 */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            if (blank($model->public_id)) {
                $model->public_id = (string) Str::uuid();
            }
        });
    }
}
