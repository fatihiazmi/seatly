<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\SeatType;
use Database\Factories\SeatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Seat extends Model
{
    /** @use HasFactory<SeatFactory> */
    use HasFactory;

    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seat_type' => SeatType::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Hall, $this>
     */
    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    /**
     * The companion seat linked to this accessible seat, if one is declared.
     *
     * @return HasOneThrough<Seat, SeatCompanionLink, $this>
     */
    public function companionSeat(): HasOneThrough
    {
        return $this->hasOneThrough(
            self::class,
            SeatCompanionLink::class,
            'accessible_seat_id',
            'id',
            'id',
            'companion_seat_id',
        );
    }

    /**
     * The accessible seat this companion seat belongs to, if any.
     *
     * @return HasOneThrough<Seat, SeatCompanionLink, $this>
     */
    public function accessibleSeat(): HasOneThrough
    {
        return $this->hasOneThrough(
            self::class,
            SeatCompanionLink::class,
            'companion_seat_id',
            'id',
            'id',
            'accessible_seat_id',
        );
    }
}
