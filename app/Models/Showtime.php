<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\ShowtimeStatus;
use Database\Factories\ShowtimeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $movie_id
 * @property int $hall_id
 * @property ShowtimeStatus $status
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $published_at
 * @property Carbon|null $cancelled_at
 * @property int $price_minor
 * @property string $currency
 * @property int $cancellation_cutoff_minutes
 * @property int $version
 * @property-read Movie $movie
 * @property-read Hall $hall
 */
class Showtime extends Model
{
    /** @use HasFactory<ShowtimeFactory> */
    use HasFactory;

    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShowtimeStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sales_open_at' => 'datetime',
            'sales_close_at' => 'datetime',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'price_minor' => 'integer',
            'cancellation_cutoff_minutes' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * @return BelongsTo<Hall, $this>
     */
    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    /**
     * @return HasMany<ShowtimeSeat, $this>
     */
    public function seatInventory(): HasMany
    {
        return $this->hasMany(ShowtimeSeat::class);
    }

    /**
     * Showtimes that are authoritatively still on sale.
     *
     * There is no stored ENDED state (RC-03), so this is derived from the
     * PostgreSQL clock inside whatever transaction asks. A finished showtime is
     * therefore never bookable, even if no maintenance job has run.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOnSale(Builder $query): void
    {
        $query->where('status', ShowtimeStatus::Published)
            ->whereRaw('transaction_timestamp() < starts_at');
    }
}
