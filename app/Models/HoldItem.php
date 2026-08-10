<?php

namespace App\Models;

use App\Enums\SeatType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable checkout line: the seat as it was when the customer was quoted.
 *
 * The snapshot exists so a later hall rename, seat reclassification or price
 * change cannot rewrite what was agreed. LOGICAL_ERD.md §2.4.
 *
 * Composite primary key `(hold_id, seat_id)`, which Eloquent does not model —
 * the same limitation ShowtimeSeat documents, with the same consequence:
 * find(), chunkById() and each() are unusable here.
 *
 * @property int $hold_id
 * @property int $showtime_id
 * @property int $seat_id
 * @property string $seat_label_snapshot
 * @property string $row_label_snapshot
 * @property string $seat_number_snapshot
 * @property SeatType $seat_type_snapshot
 * @property int $price_minor
 * @property string $currency
 */
class HoldItem extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seat_type_snapshot' => SeatType::class,
            'price_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Hold, $this>
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class);
    }

    /**
     * @return BelongsTo<Seat, $this>
     */
    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }
}
