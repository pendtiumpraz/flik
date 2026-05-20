<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Doctor\HealthChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * flik:doctor — comprehensive operational health check.
 *
 * Designed for two consumers:
 *   1. Operators running it interactively (`php artisan flik:doctor`).
 *      Prints a colourised table per section + summary footer.
 *   2. Monitoring systems polling it on a schedule (`flik:doctor --quick --json`).
 *      Emits a JSON document and sets a non-zero exit code when any check
 *      returns `fail`, so a cron job can pipe straight into alerting.
 *
 * Flags:
 *   --json          machine-readable output, suppresses tables
 *   --quick         skip network-touching checks (external API probes etc.)
 *   --section=name  run only one category, e.g. --section=database
 *
 * Exit codes:
 *   0 — every check returned `ok` or `warn`.
 *   1 — at least one `fail`.
 */
class Doctor extends Command
{
    protected $signature = 'flik:doctor
        {--json : Emit machine-readable JSON instead of human tables}
        {--quick : Skip network-touching checks}
        {--section= : Restrict to a single category (system, database, storage, cache, queue, mail, redis, disks, ai, security, cron, external, pwa)}';

    protected $description = 'Run a comprehensive health check across system, DB, storage, cache, queues, mail, AI, security, cron and PWA.';

    public function handle(HealthChecker $checker): int
    {
        // Heartbeat — every doctor run also serves as a soft scheduler liveness
        // marker. The scheduler block in Kernel calls this on its own cadence;
        // we additionally stamp here so manual runs reset the staleness clock.
        try {
            Cache::put('doctor:scheduler_heartbeat', now()->toIso8601String(), 600);
        } catch (\Throwable) {
            // best-effort
        }

        $quick = (bool) $this->option('quick');
        $section = $this->option('section');
        $section = is_string($section) && $section !== '' ? $section : null;

        $results = $checker->runAll($quick, $section);
        $summary = $checker->summarise($results);

        if ($this->option('json')) {
            $this->line(json_encode([
                'generated_at' => now()->toIso8601String(),
                'quick'        => $quick,
                'section'      => $section,
                'summary'      => $summary,
                'sections'     => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $summary['fail'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->renderHumanReport($results, $summary);

        return $summary['fail'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, array<int, array{name:string,status:string,message:string,fix:?string}>>  $results
     * @param  array{ok:int,warn:int,fail:int,total:int,overall:string}  $summary
     */
    private function renderHumanReport(array $results, array $summary): void
    {
        $this->newLine();
        $this->line('  <fg=yellow;options=bold>FLiK Doctor</> — operational health report');
        $this->line('  '.now()->toIso8601String());
        $this->newLine();

        foreach ($results as $section => $checks) {
            $this->line("  <fg=cyan;options=bold>".strtoupper($section)."</>");

            $rows = [];
            foreach ($checks as $c) {
                $rows[] = [
                    $this->statusGlyph($c['status']),
                    $c['name'],
                    $this->truncate($c['message'], 70),
                ];
            }

            $this->table(['', 'Check', 'Result'], $rows);

            // List remediation hints right under the table so an operator
            // sees the fix without scrolling.
            foreach ($checks as $c) {
                if (($c['status'] ?? '') !== 'ok' && ! empty($c['fix'])) {
                    $color = $c['status'] === 'fail' ? 'red' : 'yellow';
                    $this->line("    <fg={$color}>→ {$c['name']}:</> {$c['fix']}");
                }
            }
            $this->newLine();
        }

        $color = match ($summary['overall']) {
            'ok'   => 'green',
            'warn' => 'yellow',
            default => 'red',
        };
        $glyph = $summary['overall'] === 'ok' ? 'OK' : strtoupper($summary['overall']);
        $this->line(sprintf(
            "  <fg=%s;options=bold>SUMMARY: %s</>  —  %d ok / %d warn / %d fail  (total %d)",
            $color,
            $glyph,
            $summary['ok'],
            $summary['warn'],
            $summary['fail'],
            $summary['total'],
        ));
        $this->newLine();
    }

    private function statusGlyph(string $status): string
    {
        // No emoji to keep CI logs ASCII-clean; ANSI colour is fine.
        return match ($status) {
            'ok'   => '<fg=green;options=bold>[OK]</>',
            'warn' => '<fg=yellow;options=bold>[!!]</>',
            'fail' => '<fg=red;options=bold>[XX]</>',
            default => $status,
        };
    }

    private function truncate(string $s, int $max): string
    {
        return strlen($s) <= $max ? $s : substr($s, 0, $max - 1).'…';
    }
}
