<?php

namespace App\Actions\Showtimes;

use App\Enums\SeatInventoryStatus;
use App\Enums\ShowtimeStatus;
use App\Exceptions\ShowtimeNotPublishable;
use App\Models\Showtime;
use Illuminate\Support\Facades\DB;

/**
 * ST-03 — publish a draft showtime and materialize its seat inventory.
 *
 * Publication is the moment a showtime becomes sellable, so it is also the
 * moment its inventory must exist in full. Both happen in one transaction:
 * either the showtime is PUBLISHED with exactly one AVAILABLE row per active
 * hall seat, or nothing changed.
 *
 * Trace: FR-003/039/040; RULE-001/004; ADR-005; AC-015;
 * DOMAIN_STATE_MODELS.md §4.2 ST-03, §5.2 INV-01; LOGICAL_ERD.md §2.3.
 *
 * Not yet included, and deliberately: the SHOWTIME_PUBLISHED outbox event and
 * the audit record. Both tables arrive at migration sequencing step 14 with the
 * transactional outbox in Phase 5. Authorization is likewise absent — roles
 * land with O6-01.
 */
class PublishShowtime
{
    public function handle(Showtime $showtime): Showtime
    {
        return DB::transaction(function () use ($showtime): Showtime {
            // Lock the showtime first, per the global lock order in
            // LOGICAL_ERD.md §6. Publication does not touch inventory rows that
            // already exist, but taking the same lock in the same order keeps
            // this path consistent with the hold and confirmation transactions.
            $locked = Showtime::query()
                ->whereKey($showtime->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard($locked);

            $inserted = $this->materializeInventory($locked);

            if ($inserted === 0) {
                // Guarded above, so reaching here means the layout changed under
                // us between the guard and the insert.
                throw ShowtimeNotPublishable::hallHasNoActiveSeats();
            }

            DB::table('showtimes')
                ->where('id', $locked->getKey())
                ->update([
                    'status' => ShowtimeStatus::Published->value,
                    'published_at' => DB::raw('transaction_timestamp()'),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => DB::raw('transaction_timestamp()'),
                ]);

            return $locked->refresh();
        });
    }

    private function guard(Showtime $showtime): void
    {
        if ($showtime->status !== ShowtimeStatus::Draft) {
            throw ShowtimeNotPublishable::notDraft($showtime->status->value);
        }

        // The server clock decides, never the caller's. RULE-021, ADR-004.
        $hasStarted = DB::scalar('SELECT transaction_timestamp() >= ?', [$showtime->starts_at]);

        if ($hasStarted) {
            throw ShowtimeNotPublishable::alreadyStarted();
        }

        $showtime->loadMissing(['movie', 'hall.venue']);

        if (! $showtime->movie->is_active) {
            throw ShowtimeNotPublishable::movieNotActive();
        }

        if (! $showtime->hall->is_active) {
            throw ShowtimeNotPublishable::hallNotActive();
        }

        if (! $showtime->hall->venue->is_active) {
            throw ShowtimeNotPublishable::venueNotActive();
        }

        if (! $showtime->hall->seats()->where('is_active', true)->exists()) {
            throw ShowtimeNotPublishable::hallHasNoActiveSeats();
        }

        $this->guardHallSchedule($showtime);
    }

    /**
     * The showtimes exclusion constraint is the backstop. Checking first turns a
     * constraint violation into a named refusal the caller can act on.
     */
    private function guardHallSchedule(Showtime $showtime): void
    {
        $conflicts = DB::table('showtimes')
            ->where('hall_id', $showtime->hall_id)
            ->where('id', '!=', $showtime->getKey())
            ->where('status', ShowtimeStatus::Published->value)
            ->whereRaw(
                "tstzrange(starts_at, ends_at, '[)') && tstzrange(?, ?, '[)')",
                [$showtime->starts_at, $showtime->ends_at]
            )
            ->exists();

        if ($conflicts) {
            throw ShowtimeNotPublishable::hallScheduleConflict();
        }
    }

    /**
     * INV-01 — one AVAILABLE row per active seat in the hall, carrying the
     * price snapshot.
     *
     * Set-based on purpose: a single INSERT ... SELECT cannot interleave with a
     * layout change halfway through the way a per-seat loop could, and the
     * (showtime_id, seat_id) primary key makes a duplicate impossible.
     */
    private function materializeInventory(Showtime $showtime): int
    {
        return DB::affectingStatement(<<<'SQL'
            INSERT INTO showtime_seats (
                showtime_id, seat_id, status, price_minor, currency, version, updated_at
            )
            SELECT ?, s.id, ?, ?, ?, 1, transaction_timestamp()
            FROM seats s
            WHERE s.hall_id = ?
              AND s.is_active
            ORDER BY s.sort_order
        SQL, [
            $showtime->getKey(),
            SeatInventoryStatus::Available->value,
            $showtime->price_minor,
            $showtime->currency,
            $showtime->hall_id,
        ]);
    }
}
