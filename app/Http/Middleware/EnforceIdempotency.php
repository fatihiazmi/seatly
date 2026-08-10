<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * H3-03 — scoped idempotency and lost-response recovery.
 *
 * The case that matters is not a double click. It is the request that ran,
 * committed, and whose response was lost in transit: the client cannot tell that
 * apart from a request that never arrived, and the only safe retry is one that
 * returns the original outcome rather than acquiring seats a second time.
 *
 * Sits ahead of validation and the action, which is also where LOGICAL_ERD.md §6
 * puts it in the lock order — the idempotency record is claimed before the
 * showtime is locked.
 *
 * The claim is written in its own transaction, before the command runs, so a
 * duplicate arriving mid-flight can see it. That is what makes the concurrent
 * case answerable at all; the cost is that a process killed between claim and
 * completion leaves a record stuck `IN_PROGRESS` until the retention sweep
 * clears it. Recorded rather than hidden — the Phase 4 expiry worker is the
 * natural owner of reclaiming those.
 *
 * Trace: FR-017/028/029; RULE-006; NFR-002/007; AC-009; EX-09/13;
 * DOMAIN_STATE_MODELS.md §13, §15 (`IDEMPOTENCY_KEY_REUSED`).
 */
class EnforceIdempotency
{
    private const HEADER = 'Idempotency-Key';

    /**
     * LOGICAL_ERD.md §2.9. Long enough to cover any realistic client retry,
     * short enough that the table is not an archive.
     */
    private const RETENTION_DAYS = 30;

    /**
     * Statuses whose outcome is worth protecting. A 201 is an acquisition and a
     * 409 is a deterministic refusal — both are answers the command produced.
     *
     * Anything else (400, 404, 422) means the command never ran, so the key is
     * released: burning it would force a customer who mistyped a seat to invent
     * a new key before they could correct themselves.
     *
     * @var array<int, int>
     */
    private const SETTLED = [201, 409];

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = trim((string) $request->header(self::HEADER));

        if ($key === '') {
            return $this->refuse(400, 'IDEMPOTENCY_KEY_REQUIRED', sprintf(
                'Send an %s header so this request can be retried safely.',
                self::HEADER
            ));
        }

        $principal = 'user:'.$request->user()?->getAuthIdentifier();
        $keyHash = hash('sha256', $key);
        $fingerprint = $this->fingerprint($request);

        if (! $this->claim($scope, $principal, $keyHash, $fingerprint)) {
            return $this->recover($scope, $principal, $keyHash, $fingerprint);
        }

        $response = $next($request);

        $this->settle($scope, $principal, $keyHash, $response);

        return $response;
    }

    /**
     * Claim the key, or report that someone else already has.
     *
     * `insertOrIgnore` compiles to `INSERT ... ON CONFLICT DO NOTHING`, so the
     * unique index decides the winner rather than a read-then-write race.
     */
    private function claim(string $scope, string $principal, string $keyHash, string $fingerprint): bool
    {
        $now = Carbon::now();

        return DB::table('idempotency_keys')->insertOrIgnore([
            'scope' => $scope,
            'principal_key' => $principal,
            'key_hash' => $keyHash,
            'request_fingerprint' => $fingerprint,
            'status' => 'IN_PROGRESS',
            'created_at' => $now,
            'expires_at' => $now->copy()->addDays(self::RETENTION_DAYS),
        ]) === 1;
    }

    /**
     * The key already exists. Three outcomes, and only one of them replays.
     */
    private function recover(string $scope, string $principal, string $keyHash, string $fingerprint): Response
    {
        $existing = DB::table('idempotency_keys')
            ->where('scope', $scope)
            ->where('principal_key', $principal)
            ->where('key_hash', $keyHash)
            ->first();

        if ($existing === null) {
            // The row was swept between the failed insert and this read. Nothing
            // to recover and nothing safely retryable in this request.
            return $this->refuse(409, 'IDEMPOTENCY_IN_PROGRESS', 'That request is still being processed.');
        }

        $row = (array) $existing;

        if ((string) $row['request_fingerprint'] !== $fingerprint) {
            // §15: replaying the first response would describe a request that
            // was never made, and running the second would break the guarantee
            // the key exists to give.
            return $this->refuse(
                409,
                'IDEMPOTENCY_KEY_REUSED',
                'That idempotency key was already used for a different request.'
            );
        }

        if ((string) $row['status'] === 'IN_PROGRESS') {
            return $this->refuse(409, 'IDEMPOTENCY_IN_PROGRESS', 'That request is still being processed.');
        }

        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode((string) $row['response_snapshot'], true) ?: [];

        return new JsonResponse($snapshot, (int) $row['http_status']);
    }

    /**
     * Record the outcome, or release the key if the command never ran.
     */
    private function settle(string $scope, string $principal, string $keyHash, Response $response): void
    {
        $query = DB::table('idempotency_keys')
            ->where('scope', $scope)
            ->where('principal_key', $principal)
            ->where('key_hash', $keyHash);

        $status = $response->getStatusCode();

        if (! in_array($status, self::SETTLED, true)) {
            $query->delete();

            return;
        }

        $query->update([
            'status' => $status === 201 ? 'COMPLETED' : 'FAILED',
            'http_status' => $status,
            'response_snapshot' => $response->getContent() ?: '{}',
            'completed_at' => Carbon::now(),
        ]);
    }

    /**
     * A stable hash of what was asked for, not of how it was typed.
     *
     * Lists are sorted and object keys ordered before hashing, so a client
     * retrying the same seat selection is not told its key was reused because
     * the array arrived in a different order. This assumes no list in the
     * request body carries meaning in its order, which holds for every command
     * Seatly accepts — a seat selection is a set.
     */
    private function fingerprint(Request $request): string
    {
        $canonical = json_encode([
            $request->method(),
            $request->path(),
            $this->canonicalize($request->all()),
        ]);

        return hash('sha256', (string) $canonical);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);

        if (array_is_list($canonical)) {
            usort($canonical, fn (mixed $a, mixed $b): int => strcmp(
                (string) json_encode($a),
                (string) json_encode($b)
            ));

            return $canonical;
        }

        ksort($canonical);

        return $canonical;
    }

    private function refuse(int $status, string $reasonCode, string $message): JsonResponse
    {
        return new JsonResponse([
            'message' => $message,
            'reason_code' => $reasonCode,
        ], $status);
    }
}
