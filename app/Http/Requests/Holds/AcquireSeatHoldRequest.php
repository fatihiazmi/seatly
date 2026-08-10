<?php

namespace App\Http\Requests\Holds;

use App\Actions\Showtimes\GetSeatMap;
use App\Enums\SeatType;
use App\Enums\ShowtimeStatus;
use App\Models\Showtime;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * H3-01 — everything a hold request can be rejected for before a transaction
 * opens.
 *
 * This is the cheap gate, not the authority. It rejects what is decidable from
 * the request itself so `H3-02` only has to defend against what genuinely needs
 * locks. Deliberately absent: any availability check. A seat's status is read
 * under `lockForUpdate()` in the acquisition transaction, and duplicating that
 * read here would add a second answer that can disagree with the first.
 *
 * Status codes, decided here because the requirements name none:
 *
 * - 404 — the showtime is not publicly resolvable (draft or unknown).
 * - 409 — the showtime is not on sale. `H3-02` returns the same status for a
 *   seat lost under lock, so every losing request in the `H3-04` harness lands
 *   in one class rather than two.
 * - 422 — anything the customer can fix by editing the request.
 *
 * Trace: FR-001/003/006-010; RULE-003/010-013; PP-01-04/10; AC-002/004.
 */
class AcquireSeatHoldRequest extends FormRequest
{
    private ?Showtime $showtime = null;

    /**
     * The showtime's seats keyed by public ID, as the customer's own map showed
     * them.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $seatIndex = [];

    /**
     * Authorization is the `auth` middleware's job: FR-001 requires only that
     * the customer is authenticated, and a hold has no owner to compare against
     * until it exists.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Resolve the showtime before any rule runs, so an unresolvable or
     * closed showtime answers 404/409 rather than a validation message about
     * seats belonging to it.
     */
    protected function prepareForValidation(): void
    {
        $showtime = Showtime::query()
            ->where('public_id', (string) $this->route('showtime'))
            // Same set the public map serves: a draft has no materialized
            // inventory and is not disclosed; a cancelled showtime resolves so
            // the customer is told why rather than shown a 404. ADR-005/FR-003.
            ->whereIn('status', [ShowtimeStatus::Published->value, ShowtimeStatus::Cancelled->value])
            ->first();

        abort_if($showtime === null, 404);

        // One read for both facts, and the same read the customer's map came
        // from, so validation cannot describe a showtime differently from the
        // page the request was made on.
        $map = app(GetSeatMap::class)->handle($showtime);

        /** @var array<string, mixed> $summary */
        $summary = $map['showtime'];
        abort_if($summary['on_sale'] !== true, 409, 'This showtime is no longer on sale.');

        /** @var array<int, array<string, mixed>> $seats */
        $seats = $map['seats'];

        $this->showtime = $showtime;
        foreach ($seats as $seat) {
            $this->seatIndex[(string) $seat['id']] = $seat;
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // RULE-011/PP-02/AC-004: eight is the approved maximum, and
            // holds.seat_count carries the same bound as a CHECK constraint.
            'seat_ids' => ['required', 'array', 'min:1', 'max:8'],
            // `distinct` is the first line of DOMAIN_STATE_MODELS.md §12.2:
            // eight copies of one seat is not a request for eight seats.
            'seat_ids.*' => ['uuid', 'distinct'],
            'accessibility_acknowledged' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * AC-004 requires an accessible validation message, and the defaults name
     * the payload key: "The seat_ids.0 field must be a valid UUID" is a
     * developer's sentence, not a customer's.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'seat_ids.required' => 'Select at least one seat.',
            'seat_ids.min' => 'Select at least one seat.',
            'seat_ids.max' => 'You can hold up to 8 seats in one booking.',
            'seat_ids.*.uuid' => 'This is not a seat we recognise.',
            'seat_ids.*.distinct' => 'This seat was selected more than once.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Shape first. Resolving identifiers is pointless while the
                // list is the wrong size or carries a value that is not a UUID.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateSeatsBelongToShowtime($validator);

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateAccessibleDeclaration($validator);
                $this->validateCompanionPairing($validator);
            },
        ];
    }

    /**
     * The showtime this hold is for. Resolved during validation, so it is safe
     * to read once the request has been validated.
     */
    public function showtime(): Showtime
    {
        // prepareForValidation() aborts rather than returning null, so reaching
        // here with no showtime would mean the request was never validated.
        abort_if($this->showtime === null, 500);

        return $this->showtime;
    }

    /**
     * Every requested seat must be part of *this* showtime's inventory.
     *
     * HLD-01 and the `hold_items` constraints both require one showtime per
     * hold, and an unknown identifier is the same rejection as a seat borrowed
     * from another hall — neither is holdable here.
     */
    private function validateSeatsBelongToShowtime(Validator $validator): void
    {
        foreach ($this->requestedSeatIds() as $index => $id) {
            if (! isset($this->seatIndex[$id])) {
                $validator->errors()->add(
                    "seat_ids.{$index}",
                    'This seat is not part of this showtime.'
                );
            }
        }
    }

    /**
     * PP-10 and MVP_POLICY_BASELINE.md §1: an accessible seat requires an
     * unticked self-declaration. Only the acknowledgement is asked for — never
     * a disability type, diagnosis, evidence or document.
     */
    private function validateAccessibleDeclaration(Validator $validator): void
    {
        foreach ($this->requestedSeats() as $seat) {
            if ($seat['type'] === SeatType::Accessible->value && ! $this->boolean('accessibility_acknowledged')) {
                $validator->errors()->add(
                    'accessibility_acknowledged',
                    'Confirm that someone in this booking requires accessible seating.'
                );

                return;
            }
        }
    }

    /**
     * MVP_POLICY_BASELINE.md §1: a companion seat may be held only alongside
     * its linked accessible seat, in the same booking and showtime. A companion
     * seat with no link at all is likewise not holdable on its own.
     */
    private function validateCompanionPairing(Validator $validator): void
    {
        $requested = $this->requestedSeats();

        foreach ($requested as $seat) {
            if ($seat['type'] !== SeatType::Companion->value) {
                continue;
            }

            $linked = $seat['linked_seat_id'];

            if (! is_string($linked) || ! isset($requested[$linked])) {
                $validator->errors()->add(
                    'seat_ids',
                    sprintf(
                        'Seat %s can only be held together with its linked accessible seat.',
                        (string) $seat['label']
                    )
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function requestedSeatIds(): array
    {
        /** @var array<int, mixed> $ids */
        $ids = (array) $this->input('seat_ids', []);

        return array_map(fn (mixed $id): string => (string) $id, $ids);
    }

    /**
     * The requested seats as the map describes them, keyed by public ID.
     *
     * @return array<string, array<string, mixed>>
     */
    private function requestedSeats(): array
    {
        $seats = [];

        foreach ($this->requestedSeatIds() as $id) {
            if (isset($this->seatIndex[$id])) {
                $seats[$id] = $this->seatIndex[$id];
            }
        }

        return $seats;
    }
}
