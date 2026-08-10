<?php

namespace App\Console\Commands;

use App\Actions\Holds\AcquireSeatHold;
use App\Actions\Showtimes\PublishShowtime;
use App\Exceptions\SeatsUnavailable;
use App\Models\Hall;
use App\Models\Movie;
use App\Models\Seat;
use App\Models\Showtime;
use App\Models\User;
use App\Models\Venue;
use App\Support\Invariants\AllocationInvariants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * H3-04/H3-05/H3-07 — `<CONCURRENCY_COMMAND>`, the engineering stop gate.
 *
 * Everything before this phase is arrangement. This is the measurement: a burst
 * of genuinely parallel hold attempts on one showtime seat, and the assertion
 * that PostgreSQL let exactly one of them through.
 *
 * **Real processes, not simulated concurrency.** Each attempt is a forked child
 * with its own PostgreSQL connection, released from a shared start instant. A
 * single-process loop would serialize by construction and prove nothing about
 * locking, which is the only thing this gate exists to prove.
 *
 * **It calls the action, not the HTTP endpoint.** The invariant lives in
 * `AcquireSeatHold`'s transaction; the request layer's refusal shapes are proved
 * by `AcquireSeatHoldValidationTest` and `HoldIdempotencyTest`. Adding an HTTP
 * server here would add a process per attempt without adding evidence about row
 * locks.
 *
 * **The harness does not grade itself.** Counting its own responses could report
 * one winner while the database holds two, so every run also executes the
 * independent invariant SQL from `H3-06` against committed state.
 *
 *     php artisan seatly:concurrency-gate
 *     php artisan seatly:concurrency-gate --attempts=100 --runs=3 --seed=20260810
 *
 * Exits non-zero the moment any expectation or invariant fails. A flaky pass is
 * a failed gate.
 */
class RunConcurrencyGate extends Command
{
    protected $signature = 'seatly:concurrency-gate
        {--attempts=100 : Parallel hold attempts per run}
        {--runs=3 : Repeated seeded runs per scenario}
        {--seed=20260810 : Fixture seed, so a run is reproducible}
        {--scenario=both : same-seat, overlapping, or both}
        {--lead=1.0 : Seconds between forking and the shared release instant}
        {--output= : Directory to write the JSON report into}';

    protected $description = 'Run the real-PostgreSQL seat contention gate (H3-04/H3-05) and the invariant SQL (H3-06)';

    private const SCENARIO_SAME_SEAT = 'same-seat';

    private const SCENARIO_OVERLAPPING = 'overlapping';

    public function handle(AllocationInvariants $invariants): int
    {
        if (! function_exists('pcntl_fork')) {
            $this->error('The gate needs ext-pcntl: parallel attempts cannot be faked in one process.');

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('The gate needs PostgreSQL. Nothing else demonstrates the locking it measures.');

            return self::FAILURE;
        }

        $attempts = max(1, (int) $this->option('attempts'));
        $runs = max(1, (int) $this->option('runs'));
        $seed = (int) $this->option('seed');
        $scenarios = $this->scenarios();

        $environment = $this->environment();
        $this->describeEnvironment($environment);

        $report = [
            'generated_at' => now()->toIso8601String(),
            'environment' => $environment,
            'parameters' => compact('attempts', 'runs', 'seed') + ['scenarios' => $scenarios],
            'runs' => [],
        ];

        $failed = false;

        foreach ($scenarios as $scenario) {
            for ($run = 1; $run <= $runs; $run++) {
                $result = $this->executeRun($scenario, $run, $attempts, $seed, $invariants);
                $report['runs'][] = $result;

                $this->reportRun($result);

                if (! $result['passed']) {
                    $failed = true;
                }
            }
        }

        $report['passed'] = ! $failed;

        $this->writeReport($report);

        $this->newLine();

        if ($failed) {
            $this->error('CONCURRENCY GATE FAILED. Phases 4-6 remain blocked.');

            return self::FAILURE;
        }

        $this->info('Concurrency gate passed.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function scenarios(): array
    {
        return match ((string) $this->option('scenario')) {
            self::SCENARIO_SAME_SEAT => [self::SCENARIO_SAME_SEAT],
            self::SCENARIO_OVERLAPPING => [self::SCENARIO_OVERLAPPING],
            default => [self::SCENARIO_SAME_SEAT, self::SCENARIO_OVERLAPPING],
        };
    }

    /**
     * One run: build a fresh fixture, release the burst, collect, judge.
     *
     * @return array<string, mixed>
     */
    private function executeRun(
        string $scenario,
        int $run,
        int $attempts,
        int $seed,
        AllocationInvariants $invariants,
    ): array {
        // Same seed every run, but a fresh showtime, so runs are reproducible
        // and independent of each other rather than reproducible because they
        // share state.
        mt_srand($seed + $run);

        [$showtime, $seats, $users] = $this->buildFixture($attempts, $seed + $run);

        $plan = $this->plan($scenario, $attempts, $seats, $users);

        $deadlocksBefore = $this->deadlockCount();

        $outcomes = $this->burst($showtime, $plan);

        $deadlocks = $this->deadlockCount() - $deadlocksBefore;

        $classified = $this->classify($outcomes);
        $window = $this->coordinationWindowMs($outcomes);
        $invariantResults = $invariants->run();
        $expectations = $this->judge($scenario, $showtime, $attempts, $classified, $invariantResults, $invariants, $window);

        return [
            'scenario' => $scenario,
            'run' => $run,
            'seed' => $seed + $run,
            'attempts' => $attempts,
            'showtime_id' => $showtime->public_id,
            'deadlocks_detected' => $deadlocks,
            'coordination_window_ms' => $window,
            'outcomes' => $classified,
            'latency_ms' => $this->latency($outcomes),
            'invariants' => array_map(fn (array $r): array => [
                'name' => $r['name'],
                'violations' => $r['violations'],
                'deferred' => $r['deferred'],
                'sample' => $r['sample'],
            ], $invariantResults),
            'expectations' => $expectations,
            'passed' => ! in_array(false, array_column($expectations, 'met'), true),
        ];
    }

    /**
     * A hall with ten seats, one published showtime, and one customer per
     * attempt. Every attempt is a different customer: two requests from the same
     * account would be a different test.
     *
     * @return array{Showtime, array<int, Seat>, array<int, int>}
     */
    private function buildFixture(int $attempts, int $seed): array
    {
        fake()->seed($seed);

        $venue = Venue::factory()->create();
        $hall = Hall::factory()->for($venue)->create();
        $movie = Movie::factory()->create();

        $seats = [];

        for ($i = 0; $i < 10; $i++) {
            $seats[] = Seat::factory()->for($hall)->create([
                'row_label' => 'A',
                'seat_number' => (string) ($i + 1),
                'display_label' => 'A'.($i + 1),
                'sort_order' => $i,
            ]);
        }

        $showtime = Showtime::factory()->for($hall)->for($movie)->create([
            'price_minor' => 1850,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);

        app(PublishShowtime::class)->handle($showtime);

        $users = User::factory()->count($attempts)->create()->pluck('id')->all();

        return [$showtime->fresh(), $seats, $users];
    }

    /**
     * What each child will ask for. Computed before the fork so every child
     * inherits an identical, deterministic plan.
     *
     * @param  array<int, Seat>  $seats
     * @param  array<int, int>  $users
     * @return array<int, array{user_id: int, seat_ids: array<int, string>}>
     */
    private function plan(string $scenario, int $attempts, array $seats, array $users): array
    {
        // H3-05: three overlapping pairs drawn from three seats. Any two of them
        // share a seat, so at most one can win — and a losing request must not
        // keep the seat it did not have to fight for.
        $groups = [
            [$seats[0]->public_id, $seats[1]->public_id],
            [$seats[1]->public_id, $seats[2]->public_id],
            [$seats[0]->public_id, $seats[2]->public_id],
        ];

        $plan = [];

        for ($i = 0; $i < $attempts; $i++) {
            $plan[] = [
                'user_id' => $users[$i],
                'seat_ids' => $scenario === self::SCENARIO_SAME_SEAT
                    ? [$seats[0]->public_id]
                    : $groups[$i % 3],
            ];
        }

        return $plan;
    }

    /**
     * Fork one child per attempt, release them together, collect their results.
     *
     * The parent disconnects before forking. A child that inherited the parent's
     * open socket would corrupt it for everyone — the classic fork-with-PDO bug,
     * and one that would show up here as unexplained failures rather than as
     * anything about seat allocation.
     *
     * @param  array<int, array{user_id: int, seat_ids: array<int, string>}>  $plan
     * @return array<int, array<string, mixed>>
     */
    private function burst(Showtime $showtime, array $plan): array
    {
        $directory = storage_path('app/concurrency-gate/'.Str::uuid());

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        $showtimeId = $showtime->getKey();
        $releaseAt = microtime(true) + (float) $this->option('lead');

        DB::disconnect();

        $pids = [];

        foreach ($plan as $index => $attempt) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->error('fork() failed at attempt '.$index);

                break;
            }

            if ($pid === 0) {
                $this->attempt($directory, $index, $showtimeId, $attempt, $releaseAt);

                exit(0);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();

        return $this->collect($directory, count($pids));
    }

    /**
     * One child. Reconnects, waits for the shared instant, makes exactly one
     * attempt, and always writes a result — a child that dies silently would
     * shrink the denominator and flatter the gate.
     *
     * @param  array{user_id: int, seat_ids: array<int, string>}  $attempt
     */
    private function attempt(string $directory, int $index, int $showtimeId, array $attempt, float $releaseAt): void
    {
        $result = ['index' => $index, 'outcome' => 'ERROR', 'reason' => 'child produced no result'];

        try {
            DB::reconnect();

            $user = User::query()->findOrFail($attempt['user_id']);
            $showtime = Showtime::query()->findOrFail($showtimeId);

            // Warm the connection and the models before the barrier, so the
            // measured window is contention and not lazy loading.
            $this->sleepUntil($releaseAt);

            $startedAt = hrtime(true);
            $releasedAt = microtime(true);

            try {
                $hold = app(AcquireSeatHold::class)->handle($user, $showtime, $attempt['seat_ids']);

                $result = [
                    'index' => $index,
                    'outcome' => 'HELD',
                    'reason' => null,
                    'hold_id' => $hold->public_id,
                    'seat_count' => $hold->seat_count,
                ];
                $result['released_at'] = $releasedAt;
            } catch (SeatsUnavailable $e) {
                $result = [
                    'index' => $index,
                    'outcome' => 'CONFLICT',
                    'reason' => $e->reasonCode(),
                ];
                $result['released_at'] = $releasedAt;
            }

            $result['latency_ms'] = round((hrtime(true) - $startedAt) / 1_000_000, 3);
        } catch (Throwable $e) {
            $result = [
                'index' => $index,
                'outcome' => 'ERROR',
                'reason' => $e::class.': '.$e->getMessage(),
            ];
        }

        file_put_contents($directory.'/'.$index.'.json', (string) json_encode($result));
    }

    /**
     * How wide the starting gun was.
     *
     * H3-04 asks for at least 100 attempts coordinated inside approximately one
     * second, and "one winner, ninety-nine conflicts" is equally what a harness
     * that ran them one after another would report. This is the number that
     * tells the two apart.
     *
     * @param  array<int, array<string, mixed>>  $outcomes
     */
    private function coordinationWindowMs(array $outcomes): float
    {
        $released = [];

        foreach ($outcomes as $outcome) {
            if (isset($outcome['released_at'])) {
                $released[] = (float) $outcome['released_at'];
            }
        }

        if (count($released) < 2) {
            return 0.0;
        }

        return round((max($released) - min($released)) * 1000, 3);
    }

    private function sleepUntil(float $instant): void
    {
        $remaining = $instant - microtime(true);

        if ($remaining > 0) {
            usleep((int) ($remaining * 1_000_000));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collect(string $directory, int $expected): array
    {
        $outcomes = [];

        for ($i = 0; $i < $expected; $i++) {
            $path = $directory.'/'.$i.'.json';

            if (! is_file($path)) {
                $outcomes[] = ['index' => $i, 'outcome' => 'ERROR', 'reason' => 'no result file: child died'];

                continue;
            }

            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode((string) file_get_contents($path), true);

            $outcomes[] = $decoded ?? ['index' => $i, 'outcome' => 'ERROR', 'reason' => 'unreadable result'];
        }

        return $outcomes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $outcomes
     * @return array{held: int, conflict: int, error: int, reasons: array<string, int>, errors: array<int, string>}
     */
    private function classify(array $outcomes): array
    {
        $held = 0;
        $conflict = 0;
        $error = 0;
        $reasons = [];
        $errors = [];

        foreach ($outcomes as $outcome) {
            match ($outcome['outcome']) {
                'HELD' => $held++,
                'CONFLICT' => $conflict++,
                default => $error++,
            };

            if ($outcome['outcome'] === 'CONFLICT') {
                $reason = (string) $outcome['reason'];
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }

            if ($outcome['outcome'] === 'ERROR') {
                $errors[] = (string) $outcome['reason'];
            }
        }

        return [
            'held' => $held,
            'conflict' => $conflict,
            'error' => $error,
            'reasons' => $reasons,
            'errors' => array_slice(array_values(array_unique($errors)), 0, 5),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $outcomes
     * @return array<string, float>
     */
    private function latency(array $outcomes): array
    {
        $samples = [];

        foreach ($outcomes as $outcome) {
            if (isset($outcome['latency_ms'])) {
                $samples[] = (float) $outcome['latency_ms'];
            }
        }

        if ($samples === []) {
            return ['min' => 0.0, 'p95' => 0.0, 'max' => 0.0];
        }

        sort($samples);

        return [
            'min' => round($samples[0], 3),
            'p95' => round($samples[(int) floor(0.95 * (count($samples) - 1))], 3),
            'max' => round($samples[count($samples) - 1], 3),
        ];
    }

    /**
     * Every claim the gate makes, stated as a checkable expectation rather than
     * as prose in a report.
     *
     * @param  array{held: int, conflict: int, error: int, reasons: array<string, int>, errors: array<int, string>}  $classified
     * @param  array<int, array{name: string, description: string, violations: int, deferred: bool, sample: array<int, array<string, mixed>>}>  $invariantResults
     * @return array<int, array{claim: string, met: bool, detail: string}>
     */
    private function judge(
        string $scenario,
        Showtime $showtime,
        int $attempts,
        array $classified,
        array $invariantResults,
        AllocationInvariants $invariants,
        float $coordinationWindowMs,
    ): array {
        $heldSeats = (int) DB::scalar(
            "SELECT count(*) FROM showtime_seats WHERE showtime_id = ? AND status = 'HELD'",
            [$showtime->getKey()]
        );

        $expectedSeats = $scenario === self::SCENARIO_SAME_SEAT ? 1 : 2;

        $expectations = [
            [
                'claim' => 'exactly one attempt acquired a hold',
                'met' => $classified['held'] === 1,
                'detail' => $classified['held'].' of '.$attempts,
            ],
            [
                'claim' => 'every other attempt received a deterministic conflict',
                'met' => $classified['conflict'] === $attempts - 1,
                'detail' => $classified['conflict'].' conflicts: '.json_encode($classified['reasons']),
            ],
            [
                'claim' => 'no attempt failed with an unhandled error',
                'met' => $classified['error'] === 0,
                'detail' => $classified['error'] === 0
                    ? 'none'
                    : implode(' | ', $classified['errors']),
            ],
            [
                'claim' => 'every attempt produced a result',
                'met' => $attempts === $classified['held'] + $classified['conflict'] + $classified['error'],
                'detail' => ($classified['held'] + $classified['conflict'] + $classified['error']).' of '.$attempts,
            ],
            [
                // H3-05: a loser that kept one of its two seats is the failure
                // mode all-or-none exists to prevent.
                'claim' => 'the winner holds its whole set and no loser retained a seat',
                'met' => $heldSeats === $expectedSeats,
                'detail' => $heldSeats.' seats HELD, expected '.$expectedSeats,
            ],
            [
                // Without this, the four claims above are equally satisfied by a
                // harness that ran its attempts one at a time.
                'claim' => 'all attempts were released inside one second',
                'met' => $coordinationWindowMs <= 1000.0,
                'detail' => $coordinationWindowMs.' ms between first and last start',
            ],
            [
                'claim' => 'the invariant SQL found nothing',
                'met' => $invariants->clean($invariantResults),
                'detail' => $this->violationSummary($invariantResults),
            ],
        ];

        return $expectations;
    }

    /**
     * @param  array<int, array{name: string, description: string, violations: int, deferred: bool, sample: array<int, array<string, mixed>>}>  $results
     */
    private function violationSummary(array $results): string
    {
        $violated = [];

        foreach ($results as $result) {
            if ($result['violations'] > 0) {
                $violated[] = $result['name'].'='.$result['violations'];
            }
        }

        return $violated === [] ? 'clean' : implode(', ', $violated);
    }

    /**
     * PostgreSQL counts detected deadlocks itself, which is more trustworthy
     * than inferring them from retries the transaction helper swallows.
     */
    private function deadlockCount(): int
    {
        return (int) DB::scalar('SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()');
    }

    /**
     * H3-07 — what a reader needs to know this run means anything.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $checksum = (string) DB::scalar(<<<'SQL'
            SELECT md5(string_agg(definition, '|' ORDER BY definition))
              FROM (
                    SELECT table_name || '.' || column_name || ':' || data_type
                           || ':' || is_nullable || ':' || coalesce(column_default, '-') AS definition
                      FROM information_schema.columns
                     WHERE table_schema = 'public'
                    UNION ALL
                    SELECT conrelid::regclass || '#' || conname || ':' || pg_get_constraintdef(oid)
                      FROM pg_constraint
                     WHERE connamespace = 'public'::regnamespace
                   ) AS schema_definition
        SQL);

        return [
            'postgres_version' => (string) DB::scalar('SHOW server_version'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'build' => trim((string) @exec('git rev-parse --short HEAD')) ?: 'unknown',
            'schema_checksum' => $checksum,
            'max_connections' => (string) DB::scalar('SHOW max_connections'),
        ];
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function describeEnvironment(array $environment): void
    {
        $this->info('Seatly concurrency gate');
        $this->table(
            ['Property', 'Value'],
            array_map(fn (string $key, string $value): array => [$key, $value], array_keys($environment), $environment)
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function reportRun(array $result): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<comment>%s</comment> run %d — %d attempts, %s',
            $result['scenario'],
            $result['run'],
            $result['attempts'],
            $result['passed'] ? '<info>PASS</info>' : '<error>FAIL</error>'
        ));

        $this->table(
            ['Claim', 'Met', 'Detail'],
            array_map(fn (array $e): array => [
                $e['claim'],
                $e['met'] ? 'yes' : 'NO',
                $e['detail'],
            ], $result['expectations'])
        );
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(array $report): void
    {
        $directory = (string) ($this->option('output') ?? '');

        if ($directory === '') {
            return;
        }

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        $path = rtrim($directory, '/').'/concurrency-gate.json';

        file_put_contents($path, (string) json_encode($report, JSON_PRETTY_PRINT));

        $this->newLine();
        $this->line('Report written to '.$path);
    }
}
