<?php

namespace App\Console\Commands;

use App\Support\Invariants\AllocationInvariants;
use Illuminate\Console\Command;

/**
 * H3-06 — `<INVARIANT_CHECK_COMMAND>`.
 *
 * Reads committed state and answers one question: does the database contain an
 * allocation that should be impossible? Independent of whatever the application
 * or a harness believes happened.
 *
 * Exits non-zero on any violation, so CI and the concurrency gate can both
 * treat it as a gate rather than as a report to read later.
 */
class CheckAllocationInvariants extends Command
{
    protected $signature = 'seatly:invariants {--json : Emit machine-readable output}';

    protected $description = 'Check the seat-allocation invariants against committed database state';

    public function handle(AllocationInvariants $invariants): int
    {
        $results = $invariants->run();

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT));

            return $invariants->clean($results) ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Invariant', 'Violations', 'Status'],
            array_map(fn (array $result): array => [
                $result['name'],
                $result['deferred'] ? '-' : (string) $result['violations'],
                $this->label($result),
            ], $results)
        );

        foreach ($results as $result) {
            if ($result['violations'] === 0) {
                continue;
            }

            $this->newLine();
            $this->error($result['name'].' — '.$result['description']);

            foreach ($result['sample'] as $row) {
                $this->line('  '.(string) json_encode($row));
            }
        }

        if (! $invariants->clean($results)) {
            $this->newLine();
            $this->error('Allocation invariants FAILED.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Allocation invariants hold.');

        return self::SUCCESS;
    }

    /**
     * @param  array{name: string, description: string, violations: int, deferred: bool, sample: array<int, array<string, mixed>>}  $result
     */
    private function label(array $result): string
    {
        if ($result['deferred']) {
            return 'DEFERRED';
        }

        return $result['violations'] === 0 ? 'ok' : 'VIOLATED';
    }
}
