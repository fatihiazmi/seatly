<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A declared pair in the fixed hall layout: one accessible seat and its single
 * companion seat.
 *
 * The cross-row rules — same hall, correct classification on each side, both
 * active — are enforced by a deferred constraint trigger in PostgreSQL, not
 * here. A model hook would be bypassed by any direct write. ADR-012.
 */
class SeatCompanionLink extends Model
{
    protected $primaryKey = 'accessible_seat_id';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return BelongsTo<Seat, $this>
     */
    public function accessibleSeat(): BelongsTo
    {
        return $this->belongsTo(Seat::class, 'accessible_seat_id');
    }

    /**
     * @return BelongsTo<Seat, $this>
     */
    public function companionSeat(): BelongsTo
    {
        return $this->belongsTo(Seat::class, 'companion_seat_id');
    }
}
