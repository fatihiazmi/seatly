<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\HoldStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One temporary exclusive claim on a set of seats in one showtime.
 *
 * Reads only. Every transition — acquisition, expiry, release, confirmation —
 * happens in locked SQL inside an explicit Action, because PostgreSQL is the
 * allocation authority and a hold's whole purpose is to be correct under
 * contention. ADR-004, RULE-004, DOMAIN_STATE_MODELS.md §6.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int $showtime_id
 * @property HoldStatus $status
 * @property int $seat_count
 * @property int $total_minor
 * @property string $currency
 * @property Carbon $created_at
 * @property Carbon $expires_at
 * @property Carbon|null $closed_at
 * @property string|null $release_reason
 * @property bool $accessible_acknowledged
 * @property Carbon|null $accessible_acknowledged_at
 * @property string|null $accessibility_policy_version
 * @property-read Showtime $showtime
 * @property-read User $user
 */
class Hold extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'public_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => HoldStatus::class,
            'seat_count' => 'integer',
            'total_minor' => 'integer',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
            'accessible_acknowledged' => 'boolean',
            'accessible_acknowledged_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<HoldItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(HoldItem::class);
    }
}
