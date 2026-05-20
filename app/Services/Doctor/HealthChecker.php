<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * HealthChecker
 * --------------------------------------------------------------------------
 * Shared engine behind both the `flik:doctor` CLI command and the
 * /admin/health admin dashboard. Each public `check*` method returns a
 * uniform check result:
 *
 *   [
 *     'name'    => string,        // human-readable label
 *     'status'  => 'ok|warn|fail',
 *     'message' => string,        // short status detail (will be displayed)
 *     'fix'     => string|null,   // remediation hint when not ok
 *   ]
 *
 * Each public `runX()` method returns an ARRAY of those rows so a single
 * category can produce multiple checks (e.g. multiple PHP extensions).
 *
 * Network-touching checks are gated behind $quick — when true they are
 * skipped (or replaced with a credential-presence check). This keeps the
 * scheduled hourly run cheap and avoids hammering third-party APIs.
 */
final class HealthChecker
{
    /**
     * Run every check and return a categorised result map:
     *
     *   [
     *     'system'   => [...checks...],
     *     'database' => [...checks...],
     *     ...
     *   ]
     *
     * @return array<string, array<int, array{name:string,status:string,message:string,fix:?string}>>
     */
    public function runAll(bool $quick = false, ?string $sectionFilter = null): array
    {
        $sections = [
            'system'    => fn () => $this->runSystem(),
            'database'  => fn () => $this->runDatabase(),
            'storage'   => fn () => $this->runStorage(),
            'cache'     => fn () => $this->runCache(),
            'queue'     => fn () => $this->runQueue(),
            'mail'      => fn () => $this->runMail(),
            'redis'     => fn () => $this->runRedis(),
            'disks'     => fn () => $this->runFilesystemDisks(),
            'ai'        => fn () => $this->runAi(),
            'security'  => fn () => $this->runSecurity(),
            'cron'      => fn () => $this->runCron(),
            'external'  => fn () => $this->runExternal($quick),
            'pwa'       => fn () => $this->runPwa(),
        ];

        $out = [];
        foreach ($sections as $key => $runner) {
            if ($sectionFilter !== null && $sectionFilter !== $key) {
                continue;
            }
            try {
                $out[$key] = $runner();
            } catch (Throwable $e) {
                // Never let one section's bug kill the whole report.
                $out[$key] = [[
                    'name'    => ucfirst($key).' runner',
                    'status'  => 'fail',
                    'message' => 'Exception while running checks: '.$e->getMessage(),
                    'fix'     => 'Inspect storage/logs/laravel.log for stack trace.',
                ]];
            }
        }

        return $out;
    }

    /**
     * Aggregate counts across every section. Used by both the CLI summary
     * footer and the admin KPI cards.
     *
     * @param  array<string, array<int, array{status:string}>>  $results
     * @return array{ok:int, warn:int, fail:int, total:int, overall:string}
     */
    public function summarise(array $results): array
    {
        $ok = 0;
        $warn = 0;
        $fail = 0;
        foreach ($results as $checks) {
            foreach ($checks as $row) {
                match ($row['status'] ?? 'fail') {
                    'ok'   => $ok++,
                    'warn' => $warn++,
                    default => $fail++,
                };
            }
        }
        $total = $ok + $warn + $fail;
        $overall = $fail > 0 ? 'fail' : ($warn > 0 ? 'warn' : 'ok');

        return compact('ok', 'warn', 'fail', 'total', 'overall');
    }

    // ── System ──────────────────────────────────────────────────────────

    /**
     * @return array<int, array{name:string,status:string,message:string,fix:?string}>
     */
    public function runSystem(): array
    {
        $checks = [];

        // PHP version — composer.json requires ^8.2. Mismatch is fatal in
        // practice (vendor packages will not autoload).
        $phpVer = PHP_VERSION;
        $checks[] = version_compare($phpVer, '8.2.0', '>=')
            ? $this->ok('PHP version', "Running PHP {$phpVer} (≥ 8.2)")
            : $this->fail('PHP version', "PHP {$phpVer} is below required 8.2", 'Upgrade PHP to 8.2 or newer. composer.json requires ^8.2.');

        // Required + optional extensions. `gd` OR `imagick` is enough for
        // image resizing — only fail when neither is present.
        $required = ['openssl', 'mbstring', 'pdo', 'tokenizer', 'json', 'curl', 'fileinfo', 'xml'];
        foreach ($required as $ext) {
            $checks[] = extension_loaded($ext)
                ? $this->ok("ext-{$ext}", "Loaded")
                : $this->fail("ext-{$ext}", 'Missing required extension', "Install php-{$ext} and reload PHP-FPM.");
        }

        $hasGd = extension_loaded('gd');
        $hasImagick = extension_loaded('imagick');
        if (! $hasGd && ! $hasImagick) {
            $checks[] = $this->fail('image processing', 'Neither gd nor imagick is installed', 'Install one of php-gd or php-imagick — required for thumbnail generation.');
        } else {
            $present = array_filter(['gd' => $hasGd, 'imagick' => $hasImagick]);
            $checks[] = $this->ok('image processing', 'Available: '.implode(', ', array_keys($present)));
        }

        // Redis client extension — only required when Redis is the cache /
        // session / queue driver. Soft warn otherwise.
        $usesRedis = in_array(Config::get('cache.default'), ['redis'], true)
            || in_array(Config::get('queue.default'), ['redis'], true)
            || in_array(Config::get('session.driver'), ['redis'], true);
        if ($usesRedis) {
            $checks[] = (extension_loaded('redis') || class_exists(\Predis\Client::class))
                ? $this->ok('redis client', 'phpredis or predis available')
                : $this->fail('redis client', 'Redis configured but no client installed', 'Install ext-redis or composer require predis/predis.');
        }

        // composer.json validity — cheap parse only.
        $composerPath = base_path('composer.json');
        if (! is_readable($composerPath)) {
            $checks[] = $this->fail('composer.json', 'Missing or unreadable', 'Restore composer.json from VCS.');
        } else {
            try {
                json_decode(file_get_contents($composerPath) ?: '', true, 512, JSON_THROW_ON_ERROR);
                $checks[] = $this->ok('composer.json', 'Parses as valid JSON');
            } catch (Throwable $e) {
                $checks[] = $this->fail('composer.json', 'Invalid JSON: '.$e->getMessage(), 'Validate the file at jsonlint.com.');
            }
        }

        return $checks;
    }

    // ── Database ────────────────────────────────────────────────────────

    public function runDatabase(): array
    {
        $checks = [];

        try {
            DB::connection()->getPdo();
            $checks[] = $this->ok('database connection', 'Connected to '.Config::get('database.default'));
        } catch (Throwable $e) {
            return [
                $this->fail('database connection', 'Cannot connect: '.$e->getMessage(), 'Check DB_* env vars and database server status.'),
            ];
        }

        // Pending migrations — best effort. If the migrations table itself
        // is missing we report once and stop (Laravel will create it on
        // first migrate).
        try {
            if (! Schema::hasTable('migrations')) {
                $checks[] = $this->warn('migrations', 'Migrations table missing', 'Run `php artisan migrate`.');
            } else {
                $ran = DB::table('migrations')->count();
                $files = count(glob(database_path('migrations/*.php')) ?: []);
                $pending = max(0, $files - $ran);
                $checks[] = $pending === 0
                    ? $this->ok('migrations', "{$ran} migrations applied, none pending")
                    : $this->warn('migrations', "{$pending} pending migration(s)", 'Run `php artisan migrate` to apply.');
            }
        } catch (Throwable $e) {
            $checks[] = $this->warn('migrations', 'Could not inspect: '.$e->getMessage(), 'Check DB permissions.');
        }

        // Key tables — the smoke-test set we know we depend on.
        $expected = ['users', 'movies', 'ai_providers', 'audit_logs', 'maintenance_state'];
        $missing = [];
        foreach ($expected as $t) {
            if (! Schema::hasTable($t)) {
                $missing[] = $t;
            }
        }
        $checks[] = $missing === []
            ? $this->ok('core tables', 'All ' . count($expected) . ' tables present')
            : $this->fail('core tables', 'Missing: '.implode(', ', $missing), 'Run `php artisan migrate --seed`.');

        // Encryption key
        $key = (string) Config::get('app.key', '');
        if ($key === '') {
            $checks[] = $this->fail('APP_KEY', 'Empty — encrypted columns will throw', 'Run `php artisan key:generate`.');
        } else {
            $checks[] = $this->ok('APP_KEY', 'Set ('.strlen($key).' chars)');
        }

        return $checks;
    }

    // ── Storage ─────────────────────────────────────────────────────────

    public function runStorage(): array
    {
        $checks = [];

        // public/storage symlink. `Storage::disk('public')->path()` resolves
        // through the link, so the cheapest reliable check is is_link() on
        // the public/storage path.
        $linkPath = public_path('storage');
        if (is_link($linkPath) || is_dir($linkPath)) {
            $checks[] = $this->ok('public/storage', is_link($linkPath) ? 'Symlink present' : 'Directory exists (not a symlink — that is fine on Windows)');
        } else {
            $checks[] = $this->fail('public/storage', 'Missing — uploaded media will 404', 'Run `php artisan storage:link`.');
        }

        // Writability of storage paths.
        foreach (['framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $sub) {
            $path = storage_path($sub);
            if (! is_dir($path)) {
                $checks[] = $this->warn("storage/{$sub}", 'Directory missing', "Create with `mkdir -p storage/{$sub}` and chmod 775.");
                continue;
            }
            $checks[] = is_writable($path)
                ? $this->ok("storage/{$sub}", 'Writable')
                : $this->fail("storage/{$sub}", 'NOT writable by web user', "chown -R www-data:www-data storage/ && chmod -R 775 storage/");
        }

        // Disk fill — soft warn at 80%, fail at 95%. `disk_free_space()`
        // can return false on weird mount points; treat that as 'warn' to
        // avoid false positives on shared hosting.
        $checks[] = $this->diskFillCheck('storage path', storage_path());
        $checks[] = $this->diskFillCheck('public path', public_path());

        return $checks;
    }

    private function diskFillCheck(string $name, string $path): array
    {
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return $this->warn("disk:{$name}", 'Could not determine free space', 'Check filesystem mount status.');
        }
        $used = $total - $free;
        $pct = (int) round(($used / $total) * 100);
        $msg = sprintf('%d%% used (%s free of %s)', $pct, $this->humanBytes((int) $free), $this->humanBytes((int) $total));
        if ($pct >= 95) {
            return $this->fail("disk:{$name}", $msg, 'Free disk space immediately — uploads/cache writes will fail.');
        }
        if ($pct >= 80) {
            return $this->warn("disk:{$name}", $msg, 'Plan to free disk space; aim < 80%.');
        }

        return $this->ok("disk:{$name}", $msg);
    }

    // ── Cache ───────────────────────────────────────────────────────────

    public function runCache(): array
    {
        $checks = [];
        $driver = (string) Config::get('cache.default');

        $key = '_doctor_roundtrip_' . bin2hex(random_bytes(4));
        $val = (string) microtime(true);
        try {
            Cache::put($key, $val, 30);
            $back = Cache::get($key);
            Cache::forget($key);
            $checks[] = $back === $val
                ? $this->ok('cache roundtrip', "Driver `{$driver}` put/get/forget works")
                : $this->fail('cache roundtrip', "Driver `{$driver}` returned wrong value", 'Verify cache backend health.');
        } catch (Throwable $e) {
            $checks[] = $this->fail('cache roundtrip', "Driver `{$driver}` exception: ".$e->getMessage(), 'Check cache backend connectivity.');
        }

        return $checks;
    }

    // ── Queue ───────────────────────────────────────────────────────────

    public function runQueue(): array
    {
        $checks = [];
        $driver = (string) Config::get('queue.default');
        $checks[] = $this->ok('queue driver', "Configured: {$driver}");

        // jobs table required for database driver; nice to have otherwise.
        $checks[] = Schema::hasTable('jobs')
            ? $this->ok('jobs table', 'Present')
            : ($driver === 'database'
                ? $this->fail('jobs table', 'Missing — DB queue driver cannot enqueue', 'Run `php artisan queue:table && php artisan migrate`.')
                : $this->warn('jobs table', 'Missing (only required for `database` driver)', null));

        $checks[] = Schema::hasTable('failed_jobs')
            ? $this->ok('failed_jobs table', 'Present')
            : $this->warn('failed_jobs table', 'Missing — failed jobs will not be recorded', 'Run `php artisan queue:failed-table && php artisan migrate`.');

        // Per-queue pending depth (database driver only — cheap query). For
        // other drivers we just skip; their dashboards have native tools.
        if ($driver === 'database' && Schema::hasTable('jobs')) {
            try {
                $rows = DB::table('jobs')
                    ->select('queue', DB::raw('count(*) as c'))
                    ->groupBy('queue')
                    ->get();
                if ($rows->isEmpty()) {
                    $checks[] = $this->ok('queue depth', 'All queues empty');
                } else {
                    foreach ($rows as $r) {
                        $depth = (int) $r->c;
                        $status = $depth > 1000 ? 'fail' : ($depth > 100 ? 'warn' : 'ok');
                        $checks[] = [
                            'name'    => "queue:{$r->queue}",
                            'status'  => $status,
                            'message' => "{$depth} pending job(s)",
                            'fix'     => $status === 'ok' ? null : 'Scale workers or investigate stuck jobs.',
                        ];
                    }
                }
            } catch (Throwable $e) {
                $checks[] = $this->warn('queue depth', 'Could not inspect: '.$e->getMessage(), null);
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            try {
                $failed = (int) DB::table('failed_jobs')->count();
                $status = $failed > 50 ? 'fail' : ($failed > 0 ? 'warn' : 'ok');
                $checks[] = [
                    'name'    => 'failed jobs',
                    'status'  => $status,
                    'message' => "{$failed} failed job(s)",
                    'fix'     => $failed === 0 ? null : 'Inspect /admin/queues and `php artisan queue:retry all` after fixing the underlying cause.',
                ];
            } catch (Throwable $e) {
                $checks[] = $this->warn('failed jobs', 'Could not count: '.$e->getMessage(), null);
            }
        }

        return $checks;
    }

    // ── Mail ────────────────────────────────────────────────────────────

    public function runMail(): array
    {
        $checks = [];
        $driver = (string) Config::get('mail.default');
        $checks[] = $this->ok('mail driver', "Configured: {$driver}");

        if (in_array($driver, ['smtp'], true)) {
            $host = (string) Config::get('mail.mailers.smtp.host');
            $user = (string) Config::get('mail.mailers.smtp.username');
            if ($host === '') {
                $checks[] = $this->fail('SMTP host', 'Empty', 'Set MAIL_HOST in .env.');
            } else {
                $checks[] = $this->ok('SMTP host', $host);
            }
            if ($user === '') {
                $checks[] = $this->warn('SMTP credentials', 'No username configured', 'Set MAIL_USERNAME / MAIL_PASSWORD if your provider requires auth.');
            } else {
                $checks[] = $this->ok('SMTP credentials', 'Username configured (hidden)');
            }
        }

        $from = (string) Config::get('mail.from.address');
        $checks[] = $from === ''
            ? $this->warn('mail.from.address', 'Not set', 'Set MAIL_FROM_ADDRESS in .env.')
            : $this->ok('mail.from.address', $from);

        return $checks;
    }

    // ── Redis ───────────────────────────────────────────────────────────

    public function runRedis(): array
    {
        $usesRedis = in_array(Config::get('cache.default'), ['redis'], true)
            || in_array(Config::get('queue.default'), ['redis'], true)
            || in_array(Config::get('session.driver'), ['redis'], true);

        if (! $usesRedis) {
            return [$this->ok('redis', 'Not configured — nothing to check')];
        }

        try {
            $pong = Redis::connection()->ping();
            $okish = $pong === true || $pong === 'PONG' || $pong === '+PONG' || $pong === 1;

            return [
                $okish
                    ? $this->ok('redis ping', 'PONG received')
                    : $this->fail('redis ping', 'Unexpected response: '.var_export($pong, true), 'Verify Redis server is running and credentials are correct.'),
            ];
        } catch (Throwable $e) {
            return [$this->fail('redis ping', $e->getMessage(), 'Verify Redis host/port/auth in config/database.php.')];
        }
    }

    // ── Filesystem disks ────────────────────────────────────────────────

    public function runFilesystemDisks(): array
    {
        $checks = [];
        $default = (string) Config::get('filesystems.default');
        $checks[] = $this->ok('filesystem default', $default);

        if ($default === 'bunny' || env('BUNNY_STORAGE_KEY') !== null) {
            $zone = env('BUNNY_STORAGE_ZONE');
            $key = env('BUNNY_STORAGE_KEY');
            if (empty($zone) || empty($key)) {
                $checks[] = $this->fail('Bunny CDN', 'BUNNY_STORAGE_ZONE/KEY missing', 'Set both env vars or change FILESYSTEM_DISK.');
            } else {
                $checks[] = $this->ok('Bunny CDN', 'Credentials present');
            }
        }

        if ($default === 's3') {
            $bucket = env('AWS_BUCKET');
            $checks[] = empty($bucket)
                ? $this->fail('S3 bucket', 'AWS_BUCKET not set', 'Set AWS_* env vars.')
                : $this->ok('S3 bucket', (string) $bucket);
        }

        return $checks;
    }

    // ── AI providers ────────────────────────────────────────────────────

    public function runAi(): array
    {
        if (! Schema::hasTable('ai_providers')) {
            return [$this->warn('AI providers table', 'Missing', 'Run `php artisan migrate`.')];
        }

        try {
            $active = (int) DB::table('ai_providers')->where('is_active', true)->count();
            if ($active === 0) {
                return [$this->fail('active AI provider', 'No ai_providers row has is_active=true', 'Visit /admin/ai-settings and enable a provider.')];
            }

            $default = (int) DB::table('ai_providers')->where('is_default', true)->where('is_active', true)->count();

            return [
                $this->ok('active AI providers', "{$active} active"),
                $default > 0
                    ? $this->ok('default AI provider', 'Configured')
                    : $this->warn('default AI provider', 'No provider marked as default', 'Pick a default at /admin/ai-settings.'),
            ];
        } catch (Throwable $e) {
            return [$this->warn('AI providers', 'Could not inspect: '.$e->getMessage(), null)];
        }
    }

    // ── Security posture ────────────────────────────────────────────────

    public function runSecurity(): array
    {
        $checks = [];

        $env = (string) Config::get('app.env');
        $debug = (bool) Config::get('app.debug');
        if ($env === 'production' && $debug) {
            $checks[] = $this->fail('APP_DEBUG', 'true in production — leaks stack traces', 'Set APP_DEBUG=false in .env on production.');
        } else {
            $checks[] = $this->ok('APP_DEBUG', $debug ? 'true (non-production env)' : 'false');
        }

        $checks[] = ((string) Config::get('app.key', '')) !== ''
            ? $this->ok('APP_KEY', 'Set')
            : $this->fail('APP_KEY', 'Empty', 'Run `php artisan key:generate`.');

        $appUrl = (string) Config::get('app.url');
        $isHttps = str_starts_with($appUrl, 'https://');
        if ($isHttps) {
            $secure = (bool) Config::get('session.secure');
            $checks[] = $secure
                ? $this->ok('SESSION_SECURE_COOKIE', 'true (HTTPS site)')
                : $this->warn('SESSION_SECURE_COOKIE', 'false but APP_URL is HTTPS', 'Set SESSION_SECURE_COOKIE=true in .env so the session cookie is only sent over HTTPS.');
        }

        // Middleware registration smoke-test. Each of these must be present
        // in the global stack — the security swarms rely on them.
        $kernel = app(\App\Http\Kernel::class);
        $reflection = new \ReflectionClass($kernel);
        $prop = $reflection->getProperty('middleware');
        $prop->setAccessible(true);
        $globalMw = (array) $prop->getValue($kernel);

        $required = [
            \App\Http\Middleware\SecurityHeaders::class => 'CSP / security headers middleware',
            \App\Http\Middleware\RequestFirewall::class => 'WAF-lite middleware',
            \App\Http\Middleware\ForceHttps::class      => 'ForceHttps middleware',
        ];
        foreach ($required as $cls => $label) {
            $checks[] = in_array($cls, $globalMw, true)
                ? $this->ok($label, 'Registered')
                : $this->fail($label, 'NOT in global middleware stack', "Add `\\{$cls}::class` to app/Http/Kernel.php \$middleware.");
        }

        return $checks;
    }

    // ── Cron / scheduler ────────────────────────────────────────────────

    public function runCron(): array
    {
        $checks = [];

        try {
            $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
            $events = $schedule->events();
            $count = count($events);
            $checks[] = $count > 0
                ? $this->ok('scheduled commands', "{$count} command(s) scheduled")
                : $this->warn('scheduled commands', 'No commands scheduled', 'Define commands in app/Console/Kernel.php::schedule().');
        } catch (Throwable $e) {
            $checks[] = $this->warn('scheduled commands', 'Could not inspect: '.$e->getMessage(), null);
        }

        // Heartbeat — the scheduler stamps this cache key every minute
        // when the cron daemon hits `schedule:run`. If it's stale by >5
        // minutes the cron job has died.
        try {
            $last = Cache::get('doctor:scheduler_heartbeat');
            if ($last === null) {
                $checks[] = $this->warn('scheduler heartbeat', 'No heartbeat recorded yet', 'Ensure `* * * * * php artisan schedule:run` is installed in crontab.');
            } else {
                $age = now()->diffInMinutes(Carbon::parse($last), true);
                $checks[] = $age <= 5
                    ? $this->ok('scheduler heartbeat', "Last seen {$age}m ago")
                    : $this->fail('scheduler heartbeat', "Last seen {$age}m ago — cron may be dead", 'Check crontab and `php artisan schedule:list` output.');
            }
        } catch (Throwable $e) {
            $checks[] = $this->warn('scheduler heartbeat', 'Cache read failed: '.$e->getMessage(), null);
        }

        return $checks;
    }

    // ── External services (credential presence only unless !$quick) ─────

    public function runExternal(bool $quick = false): array
    {
        $checks = [];

        $services = [
            'TMDB'      => ['services.tmdb.api_key', 'TMDB_API_KEY'],
            'Mailchimp' => ['services.mailchimp.api_key', 'MAILCHIMP_API_KEY'],
            'Pusher'    => ['broadcasting.connections.pusher.key', 'PUSHER_APP_KEY'],
            'Midtrans'  => ['services.midtrans.server_key', 'MIDTRANS_SERVER_KEY'],
        ];

        foreach ($services as $label => [$cfg, $envName]) {
            $val = (string) (Config::get($cfg) ?? env($envName, ''));
            if ($val === '') {
                $checks[] = $this->warn("{$label} credentials", 'Not configured (feature disabled)', "Optional — set {$envName} to enable.");
            } else {
                $checks[] = $this->ok("{$label} credentials", 'Present');
            }
        }

        if ($quick) {
            $checks[] = $this->ok('external API probes', 'Skipped (--quick)');
        }

        return $checks;
    }

    // ── PWA ─────────────────────────────────────────────────────────────

    public function runPwa(): array
    {
        $manifest = public_path('manifest.json');
        $sw = public_path('sw.js');

        return [
            File::exists($manifest)
                ? $this->ok('manifest.json', 'Present')
                : $this->fail('manifest.json', 'Missing at public/manifest.json', 'Restore from repo.'),
            File::exists($sw)
                ? $this->ok('sw.js (service worker)', 'Present')
                : $this->fail('sw.js (service worker)', 'Missing at public/sw.js', 'Restore from repo.'),
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * @return array{name:string,status:string,message:string,fix:?string}
     */
    private function ok(string $name, string $message): array
    {
        return ['name' => $name, 'status' => 'ok', 'message' => $message, 'fix' => null];
    }

    /**
     * @return array{name:string,status:string,message:string,fix:?string}
     */
    private function warn(string $name, string $message, ?string $fix = null): array
    {
        return ['name' => $name, 'status' => 'warn', 'message' => $message, 'fix' => $fix];
    }

    /**
     * @return array{name:string,status:string,message:string,fix:?string}
     */
    private function fail(string $name, string $message, ?string $fix = null): array
    {
        return ['name' => $name, 'status' => 'fail', 'message' => $message, 'fix' => $fix];
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
