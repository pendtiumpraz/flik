<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Restore an encrypted FLiK backup.
 *
 * Pipeline:
 *   download (if remote) -> decrypt -> untar -> sql restore
 *
 * Usage:
 *   php artisan flik:backup:restore db_20260513_010000.tar.gz.enc
 *   php artisan flik:backup:restore db_20260513_010000.tar.gz.enc --from-disk=bunny
 *   php artisan flik:backup:restore /absolute/path/to/file.tar.gz.enc --from-disk=local
 *   php artisan flik:backup:restore … --force                       # skip confirmation
 *   php artisan flik:backup:restore … --extract-only                # decrypt + untar, do NOT run SQL
 *
 * DESTRUCTIVE: replaces the contents of the active database. Always run
 * against a maintenance-mode app and confirm the target database. See
 * docs/security/backup-restore.md for the full procedure.
 */
class RestoreBackup extends Command
{
    protected $signature = 'flik:backup:restore
        {file : Path to a .tar.gz.enc file. May be a remote object name (relative to "backups/") or an absolute local path.}
        {--from-disk=bunny : Where to fetch the file from. One of: bunny | s3 | local.}
        {--force : Skip the interactive confirmation prompt. REQUIRED for unattended runs.}
        {--extract-only : Decrypt and untar but do NOT run the SQL restore.}';

    protected $description = 'Restore the database (and optionally bundled media) from an encrypted backup. DESTRUCTIVE.';

    public function __construct(protected BackupService $backups)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $disk = (string) $this->option('from-disk');

        $this->warn('============================================================');
        $this->warn(' DESTRUCTIVE OPERATION — restores will OVERWRITE the active');
        $this->warn(' database connection. Make sure the app is in maintenance');
        $this->warn(' mode and the target DB is correct before continuing.');
        $this->warn('============================================================');

        $connection = (string) config('database.default', 'mysql');
        $cfg = (array) config("database.connections.{$connection}", []);
        $this->line(sprintf(
            '  Target connection: %s (database=%s host=%s)',
            $connection,
            (string) ($cfg['database'] ?? '?'),
            (string) ($cfg['host'] ?? '?'),
        ));

        if (! $this->option('force')) {
            if (! $this->confirm('Continue with restore?', false)) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
            if (! $this->confirm('Confirm overwrite of database "' . ($cfg['database'] ?? '?') . '"?', false)) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        try {
            // Step 1 — fetch the encrypted artefact ----------------------------
            $encPath = $this->fetch($file, $disk);
            $this->line("  fetched   → {$encPath}");

            // Step 2 — decrypt -------------------------------------------------
            $tarPath = $this->backups->decrypt($encPath);
            $this->line("  decrypted → {$tarPath}");

            // Step 3 — untar into a working dir --------------------------------
            $workDir = $this->extract($tarPath);
            $this->line("  extracted → {$workDir}");

            if ($this->option('extract-only')) {
                $this->info('  --extract-only set; skipping SQL restore.');
                return self::SUCCESS;
            }

            // Step 4 — find the SQL file and apply -----------------------------
            $sqlFile = $this->findSqlFile($workDir);
            if ($sqlFile === null) {
                throw new RuntimeException('Restore: no .sql file found inside archive.');
            }

            $this->line("  applying  → {$sqlFile}");
            $this->applySql($sqlFile);
            $this->info('Restore COMPLETED.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Restore failed: ' . $e->getMessage());
            Log::error('flik:backup:restore failed', [
                'file'  => $file,
                'disk'  => $disk,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Resolve $file → absolute local path. Downloads from the remote disk
     * first if needed.
     */
    protected function fetch(string $file, string $disk): string
    {
        // Absolute or "local" disk → just resolve.
        if ($disk === 'local' || $this->looksLikeAbsolutePath($file)) {
            $abs = $this->looksLikeAbsolutePath($file)
                ? $file
                : storage_path('app' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $file);

            if (! is_file($abs)) {
                throw new RuntimeException("Restore: local file not found: {$abs}");
            }
            return $abs;
        }

        // Remote — fetch via the CdnStorageContract using a signed URL.
        $remote = $this->backups->resolveRemote($disk);

        // Allow $file to be either a bare basename or already prefixed.
        $remotePath = str_starts_with($file, 'backups/')
            ? $file
            : $this->guessRemotePath($remote, $file);

        $url = $remote->signedUrl($remotePath, 600);

        $localDir = storage_path('app' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'restore');
        if (! is_dir($localDir) && ! mkdir($localDir, 0750, true) && ! is_dir($localDir)) {
            throw new RuntimeException("Restore: cannot create {$localDir}");
        }
        $localPath = $localDir . DIRECTORY_SEPARATOR . basename($remotePath);

        $in  = @fopen($url, 'rb');
        $out = @fopen($localPath, 'wb');
        if ($in === false || $out === false) {
            if ($in !== false)  { fclose($in); }
            if ($out !== false) { fclose($out); }
            throw new RuntimeException("Restore: failed to download {$remotePath} from {$disk}");
        }
        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
        return $localPath;
    }

    /**
     * If the caller passed a bare basename, scan the most recent date dirs.
     */
    protected function guessRemotePath(object $remote, string $basename): string
    {
        if (! method_exists($remote, 'listFiles')) {
            return 'backups/' . $basename;
        }
        /** @var array<int, array<string, mixed>> $dirs */
        $dirs = $remote->listFiles('backups');
        // Newest date first.
        usort($dirs, fn ($a, $b) => strcmp((string) ($b['ObjectName'] ?? ''), (string) ($a['ObjectName'] ?? '')));
        foreach ($dirs as $entry) {
            $date = (string) ($entry['ObjectName'] ?? '');
            if ($date === '' || ! ($entry['IsDirectory'] ?? false)) {
                continue;
            }
            /** @var array<int, array<string, mixed>> $files */
            $files = $remote->listFiles("backups/{$date}");
            foreach ($files as $f) {
                if ((string) ($f['ObjectName'] ?? '') === $basename) {
                    return "backups/{$date}/{$basename}";
                }
            }
        }
        // Fallback — let the caller hit a 404 with a clear path.
        return 'backups/' . $basename;
    }

    protected function extract(string $tarPath): string
    {
        $workDir = dirname($tarPath) . DIRECTORY_SEPARATOR . 'extract_' . pathinfo($tarPath, PATHINFO_FILENAME);
        if (! is_dir($workDir) && ! mkdir($workDir, 0750, true) && ! is_dir($workDir)) {
            throw new RuntimeException("Restore: cannot create {$workDir}");
        }

        $tarBin = (string) config('backup.binaries.tar', 'tar');
        if ($this->binaryAvailable($tarBin)) {
            $cmd = sprintf('%s -xzf %s -C %s', escapeshellarg($tarBin), escapeshellarg($tarPath), escapeshellarg($workDir));
            $exit = 0;
            $output = [];
            exec($cmd . ' 2>&1', $output, $exit);
            if ($exit !== 0) {
                throw new RuntimeException('Restore: tar extract failed: ' . implode("\n", array_slice($output, 0, 10)));
            }
            return $workDir;
        }

        // PharData fallback — works on Windows without tar.
        try {
            $phar = new \PharData($tarPath);
            $phar->extractTo($workDir, null, true);
        } catch (\Throwable $e) {
            throw new RuntimeException('Restore: PharData extract failed: ' . $e->getMessage(), previous: $e);
        }
        return $workDir;
    }

    protected function findSqlFile(string $workDir): ?string
    {
        $candidates = (array) glob($workDir . DIRECTORY_SEPARATOR . '*.sql');
        foreach ($candidates as $c) {
            if (is_string($c) && is_file($c)) {
                return $c;
            }
        }
        // Recursive search (in case the tar layout nests).
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'sql') {
                return $f->getPathname();
            }
        }
        return null;
    }

    protected function applySql(string $sqlFile): void
    {
        // Prefer the native client (mysql/psql) for big dumps — it streams without
        // loading the whole file into memory the way DB::unprepared() would.
        $connection = (string) config('database.default', 'mysql');
        $cfg = (array) config("database.connections.{$connection}", []);
        $driver = (string) ($cfg['driver'] ?? '');

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $bin = 'mysql';
            if (! $this->binaryAvailable($bin)) {
                $this->phpRestore($sqlFile);
                return;
            }
            $defaultsFile = tempnam(sys_get_temp_dir(), 'flik-mysql-restore-');
            file_put_contents(
                $defaultsFile,
                "[client]\nuser=" . ($cfg['username'] ?? '') . "\npassword=\"" . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) ($cfg['password'] ?? '')) . "\"\n",
            );
            chmod($defaultsFile, 0600);
            try {
                $cmd = sprintf(
                    '%s --defaults-extra-file=%s --host=%s --port=%s %s < %s',
                    escapeshellarg($bin),
                    escapeshellarg($defaultsFile),
                    escapeshellarg((string) ($cfg['host'] ?? '127.0.0.1')),
                    escapeshellarg((string) ($cfg['port'] ?? '3306')),
                    escapeshellarg((string) ($cfg['database'] ?? '')),
                    escapeshellarg($sqlFile),
                );
                $exit = 0;
                $out = [];
                exec($cmd . ' 2>&1', $out, $exit);
                if ($exit !== 0) {
                    throw new RuntimeException('mysql restore failed: ' . implode("\n", array_slice($out, 0, 10)));
                }
            } finally {
                @unlink($defaultsFile);
            }
            return;
        }

        if ($driver === 'pgsql') {
            $bin = 'psql';
            if (! $this->binaryAvailable($bin)) {
                $this->phpRestore($sqlFile);
                return;
            }
            $env = [
                'PGHOST'     => (string) ($cfg['host'] ?? '127.0.0.1'),
                'PGPORT'     => (string) ($cfg['port'] ?? '5432'),
                'PGUSER'     => (string) ($cfg['username'] ?? ''),
                'PGPASSWORD' => (string) ($cfg['password'] ?? ''),
            ];
            $cmd = sprintf(
                '%s --dbname=%s --quiet --file=%s',
                escapeshellarg($bin),
                escapeshellarg((string) ($cfg['database'] ?? '')),
                escapeshellarg($sqlFile),
            );
            $proc = proc_open(
                $cmd,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $env,
            );
            if (! is_resource($proc)) {
                throw new RuntimeException('Restore: failed to spawn psql.');
            }
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            if ($exit !== 0) {
                throw new RuntimeException('psql restore failed: ' . substr($stderr, 0, 500));
            }
            return;
        }

        // sqlite or unknown driver — fall back to PHP path.
        $this->phpRestore($sqlFile);
    }

    /**
     * Last-resort restore through PDO. Only safe for small dumps — splits on
     * `;` at end-of-line, which is not 100% SQL-safe but works for the
     * mysqldump / pg_dump output formats we generate.
     */
    protected function phpRestore(string $sqlFile): void
    {
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new RuntimeException("Restore: cannot read {$sqlFile}");
        }
        // Best-effort statement split.
        $statements = preg_split('/;\s*\n/', $sql) ?: [];
        DB::unprepared(implode(";\n", array_filter(array_map('trim', $statements))));
    }

    protected function looksLikeAbsolutePath(string $p): bool
    {
        return $p !== '' && ($p[0] === '/' || (strlen($p) > 2 && $p[1] === ':'));
    }

    protected function binaryAvailable(string $bin): bool
    {
        if (is_file($bin) && is_executable($bin)) {
            return true;
        }
        $isWindows = stripos(PHP_OS, 'WIN') === 0;
        $cmd = $isWindows ? "where {$bin}" : "command -v {$bin}";
        $out = [];
        $exit = 0;
        @exec($cmd, $out, $exit);
        return $exit === 0 && ! empty($out);
    }
}
