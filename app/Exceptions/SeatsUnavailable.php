<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The deterministic conflict FR-009 requires: this request acquired nothing.
 *
 * One exception type for every reason a hold can lose, because from the
 * customer's side they are the same event — the seats are not yours — and from
 * the harness's side a losing request must be one classifiable outcome rather
 * than a family of near-misses. The reason code carries the detail.
 *
 * Never thrown after a partial mutation: the acquisition transaction rolls back
 * whole, so this exception always means nothing changed. RULE-003, AC-002.
 */
class SeatsUnavailable extends RuntimeException
{
    public const SHOWTIME_NOT_ON_SALE = 'SHOWTIME_NOT_ON_SALE';

    public const SEAT_TAKEN = 'SEAT_TAKEN';

    public const SEAT_NOT_IN_SHOWTIME = 'SEAT_NOT_IN_SHOWTIME';

    public const COMPANION_WITHOUT_ACCESSIBLE = 'COMPANION_WITHOUT_ACCESSIBLE';

    public const ACCESSIBLE_NOT_DECLARED = 'ACCESSIBLE_NOT_DECLARED';

    /**
     * @param  array<int, string>  $seatIds  public IDs of the seats that caused
     *                                       the refusal, empty when the reason
     *                                       is not seat-specific
     */
    public function __construct(
        private readonly string $reasonCode,
        private readonly array $seatIds,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function showtimeNotOnSale(): self
    {
        return new self(
            self::SHOWTIME_NOT_ON_SALE,
            [],
            'This showtime is no longer on sale.'
        );
    }

    /**
     * @param  array<int, string>  $seatIds
     */
    public static function seatsTaken(array $seatIds): self
    {
        return new self(
            self::SEAT_TAKEN,
            $seatIds,
            'Some of those seats have just been taken.'
        );
    }

    /**
     * @param  array<int, string>  $seatIds
     */
    public static function seatsNotInShowtime(array $seatIds): self
    {
        return new self(
            self::SEAT_NOT_IN_SHOWTIME,
            $seatIds,
            'Some of those seats are not part of this showtime.'
        );
    }

    /**
     * @param  array<int, string>  $seatIds
     */
    public static function companionWithoutAccessible(array $seatIds): self
    {
        return new self(
            self::COMPANION_WITHOUT_ACCESSIBLE,
            $seatIds,
            'A companion seat can only be held together with its linked accessible seat.'
        );
    }

    /**
     * @param  array<int, string>  $seatIds
     */
    public static function accessibleNotDeclared(array $seatIds): self
    {
        return new self(
            self::ACCESSIBLE_NOT_DECLARED,
            $seatIds,
            'Confirm that someone in this booking requires accessible seating.'
        );
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    /**
     * @return array<int, string>
     */
    public function seatIds(): array
    {
        return $this->seatIds;
    }
}
