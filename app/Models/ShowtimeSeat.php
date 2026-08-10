<?php

namespace App\Models;

use App\Enums\SeatInventoryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One physical seat within one showtime — the authoritative allocation record.
 *
 * Ordinary Eloquent reads are fine here. Every state transition from Phase 3
 * onward goes through locked SQL in an explicit Action, never through this
 * model, because PostgreSQL is the allocation authority. ADR-004, RULE-004.
 *
 * The primary key is the composite (showtime_id, seat_id) required by
 * LOGICAL_ERD.md §2.3, which Eloquent does not model. Consequence: the
 * key-based helpers are unavailable — find(), chunkById(), and the each()/
 * chunk() family all order by a single primary key and will fail here. Fetch
 * with get() or lazy() and an explicit orderBy instead.
 *
 * @property int $showtime_id
 * @property int $seat_id
 * @property SeatInventoryStatus $status
 * @property int|null $hold_id
 * @property int|null $booking_id
 * @property Carbon|null $hold_expires_at
 * @property int $price_minor
 * @property string $currency
 * @property int $version
 * @property string|null $blocked_reason_code
 */
class ShowtimeSeat extends Model
{
    protected $table = 'showtime_seats';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $primaryKey = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SeatInventoryStatus::class,
            'hold_expires_at' => 'datetime',
            'price_minor' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Showtime, $this>
     */
    public function showtime(): BelongsTo
    {
        return $this->belongsTo(Showtime::class);
    }

    /**
     * @return BelongsTo<Seat, $this>
     */
    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }
}
