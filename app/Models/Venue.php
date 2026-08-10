<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
    use HasFactory;

    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Hall, $this>
     */
    public function halls(): HasMany
    {
        return $this->hasMany(Hall::class);
    }

    /**
     * True while any hall in this venue has an upcoming published showtime,
     * during which the venue cannot be deactivated (`ADR-020`).
     *
     * The database enforces this with a trigger; this is the read used to refuse
     * the edit before attempting it. Both derive "upcoming" from the PostgreSQL
     * clock, so they cannot disagree.
     */
    public function hasFrozenHall(): bool
    {
        return Showtime::query()
            ->onSale()
            ->whereIn('hall_id', $this->halls()->select('id'))
            ->exists();
    }
}
