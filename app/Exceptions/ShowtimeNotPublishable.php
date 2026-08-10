<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A publication guard from ST-03 refused the command.
 *
 * The reason code is part of the result contract, not a message for humans:
 * callers and tests assert on it. DOMAIN_STATE_MODELS.md §15 lists the shared
 * vocabulary; the publication-specific codes are declared here because that
 * list covers the hold, payment, booking and cancellation paths only.
 */
class ShowtimeNotPublishable extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notDraft(string $status): self
    {
        return new self(
            'SHOWTIME_NOT_DRAFT',
            "Only a DRAFT showtime can be published; this one is {$status}."
        );
    }

    public static function alreadyStarted(): self
    {
        return new self(
            'SHOWTIME_ALREADY_STARTED',
            'A showtime that has already started cannot be published.'
        );
    }

    public static function movieNotActive(): self
    {
        return new self('MOVIE_NOT_ACTIVE', 'The movie is not active.');
    }

    public static function hallNotActive(): self
    {
        return new self('HALL_NOT_ACTIVE', 'The hall is not active.');
    }

    public static function venueNotActive(): self
    {
        return new self('VENUE_NOT_ACTIVE', 'The venue is not active.');
    }

    public static function hallHasNoActiveSeats(): self
    {
        return new self(
            'HALL_HAS_NO_ACTIVE_SEATS',
            'The hall has no active seat, so publication would create no inventory.'
        );
    }

    public static function hallScheduleConflict(): self
    {
        return new self(
            'HALL_SCHEDULE_CONFLICT',
            'Another published showtime already occupies this hall for part of that period.'
        );
    }
}
