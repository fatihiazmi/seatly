<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\HallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hall extends Model
{
    /** @use HasFactory<HallFactory> */
    use HasFactory;

    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layout_version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * @return HasMany<Seat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }

    /**
     * @return HasMany<Showtime, $this>
     */
    public function showtimes(): HasMany
    {
        return $this->hasMany(Showtime::class);
    }

    /**
     * True while an upcoming published showtime references this hall, during
     * which the sellable seat set, seat identity, ordering and classification
     * are immutable (`ADR-019`).
     *
     * The database enforces this with a trigger; this is the read used to refuse
     * an edit before attempting it, and to grey out controls in the admin UI.
     * Both ask PostgreSQL for the time, so they cannot disagree with each other
     * about "upcoming".
     */
    public function layoutIsFrozen(): bool
    {
        return $this->showtimes()->onSale()->exists();
    }
}
