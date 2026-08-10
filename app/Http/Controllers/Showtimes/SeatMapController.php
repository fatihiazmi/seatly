<?php

namespace App\Http\Controllers\Showtimes;

use App\Actions\Showtimes\GetSeatMap;
use App\Enums\ShowtimeStatus;
use App\Http\Controllers\Controller;
use App\Models\Showtime;
use Illuminate\Http\JsonResponse;

/**
 * C2-04 — public seat map.
 *
 * Public by decision: browsing needs no account, and authentication begins at
 * the hold request (`ADR-014`).
 */
class SeatMapController extends Controller
{
    public function __invoke(string $showtime, GetSeatMap $seatMap): JsonResponse
    {
        // Resolved on public_id, never the internal key: the bigint is not an
        // external identifier. LOGICAL_ERD.md §1.
        $record = Showtime::query()
            ->where('public_id', $showtime)
            // A draft has no inventory and is not public. Cancelled and past
            // showtimes are still served, so a customer on a stale link is told
            // why rather than shown a 404. FR-003.
            ->whereIn('status', [ShowtimeStatus::Published->value, ShowtimeStatus::Cancelled->value])
            ->first();

        abort_if($record === null, 404);

        return response()->json($seatMap->handle($record));
    }
}
