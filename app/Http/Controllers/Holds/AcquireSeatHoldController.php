<?php

namespace App\Http\Controllers\Holds;

use App\Actions\Holds\AcquireSeatHold;
use App\Exceptions\SeatsUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Holds\AcquireSeatHoldRequest;
use App\Models\Hold;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * H3-01/H3-02 — the hold endpoint.
 *
 * The controller authorizes (via middleware), validates (via the form request),
 * invokes one action, and maps the result. Every allocation decision belongs to
 * the action's transaction, not here.
 *
 * A refusal is 409 with a stable reason code, never 500 and never a partial 201.
 * The `H3-04` harness classifies losing requests on exactly this shape.
 */
class AcquireSeatHoldController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const SEAT_FIELDS = ['seat_label_snapshot', 'row_label_snapshot', 'seat_number_snapshot', 'seat_type_snapshot'];

    public function __invoke(AcquireSeatHoldRequest $request, AcquireSeatHold $acquire): JsonResponse
    {
        /** @var array<int, string> $seatIds */
        $seatIds = $request->validated('seat_ids');

        try {
            $hold = $acquire->handle(
                $request->user(),
                $request->showtime(),
                $seatIds,
                $request->boolean('accessibility_acknowledged'),
            );
        } catch (SeatsUnavailable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason_code' => $e->reasonCode(),
                'seat_ids' => $e->seatIds(),
            ], 409);
        }

        return response()->json($this->payload($hold), 201);
    }

    /**
     * AC-003: showtime, seats, expiry, amount and currency are all identifiable
     * from what checkout is handed. Public identifiers only.
     *
     * @return array<string, mixed>
     */
    private function payload(Hold $hold): array
    {
        $fields = implode(', ', array_map(fn (string $field): string => "hi.{$field}", self::SEAT_FIELDS));

        // Converted once, then read through explicit casts — the same shape
        // discipline GetSeatMap uses on its rows.
        $seats = array_map(fn (object $row): array => (array) $row, DB::select(
            "SELECT s.public_id, {$fields}, hi.price_minor
               FROM hold_items hi
               JOIN seats s ON s.id = hi.seat_id
              WHERE hi.hold_id = ?
              ORDER BY s.sort_order",
            [$hold->getKey()]
        ));

        return [
            'hold' => [
                'id' => $hold->public_id,
                'showtime_id' => $hold->showtime->public_id,
                'status' => $hold->status->value,
                'expires_at' => $hold->expires_at->toIso8601String(),
                'seat_count' => $hold->seat_count,
                'total_minor' => $hold->total_minor,
                'currency' => $hold->currency,
                'seats' => array_map(fn (array $row): array => [
                    'id' => (string) $row['public_id'],
                    'label' => (string) $row['seat_label_snapshot'],
                    'row' => (string) $row['row_label_snapshot'],
                    'number' => (string) $row['seat_number_snapshot'],
                    'type' => (string) $row['seat_type_snapshot'],
                    'price_minor' => (int) $row['price_minor'],
                ], $seats),
            ],
        ];
    }
}
