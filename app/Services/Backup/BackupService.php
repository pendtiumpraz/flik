<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Contracts\Storage\CdnStorageContract;
use App\Services\Storage\BunnyStorageService;
use App\Services\Storage\S3StorageService;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Encrypted backup pipeline for the FLiK platform.
 *
 * Pipeline:
 *   1. dump()    -> SQL dump of the active DB connection (mysqldump|pg_dump)
 *   2. archive() -> tar.gz of the SQL dump + optional media directories
 *   3. encrypt() -> AES-256-CBC + PBKDF2 (salted, per-backup) ciphertext
 *   4. upload()  -> push the .enc to a CdnStorageContract disk
 *   5. prune()   -> evict backups older than `retention_days` locally + remotely
 *
 * Errors are RAISED (not swallowed) so the orchestrating command can react
 * and notify super_admins. Per-step output paths are returned for logging.
 *
 * Dev-mode safety: if the dump binary is missing, dump() logs a warning and
 * writes a 1-line placeholder SQL file so the rest of the pipeline can still
 * be exercised end-to-end without a real DB toolchain.
 */
class BackupService
{
    /** OpenSSL "Salted__" magic header, matches `openssl enc -salt` format. */
    private const OPENSSL_MAGIC = 'Salted__';

    /** Cipher used for at-rest backup encryption. */
    private const CIPHER = 'aes-256-cbc';

    /** PBKDF2 iteration count — matches `openssl enc -pbkdf2` default. */
    private const PBKDF2_ITERATIONS = 10_000;

    /** Bytes streamed per encrypt loop iteration (1 MiB). */
    private const ENCRYPT_CHUNK_BYTES = 1_048_576;

    public function __construct(
        protected FilesystemManager $files,
    ) {
    }

    // ---------------------------------------------------------------------
    // Step 1 — DB dump
    // ---------------------------------------------------------------------

    /**
     * Run mysqldump or pg_dump for the active connection.
     *
     * @return string Absolute path to the produced .sql file.
     */
    public function dump(): string
    {
        $timestamp = $this->timestamp();
        $dir       = $this->ensureLocalBackupDir();
        $sqlPath   = $dir . DIRECTORY_SEPARATOR . "db_{$timestamp}.sql";

        $connection = (string) config('database.default', 'mysql');
        $cfg        = config("database.connections.{$connection}");

        if (! is_array($cfg)) {
            throw new RuntimeException("BackupService: unknown DB connection '{$connection}'.");
        }

        $driver = (string) ($cfg['driver'] ?? '');

        match ($driver) {
            'mysql', 'mariadb' => $this->dumpMysql($cfg, $sqlPath),
            'pgsql'            => $this->dumpPostgres($cfg, $sqlPath),
            'sqlite'           => $this->dumpSqlite($cfg, $sqlPath),
            default            => throw new RuntimeException("BackupService: unsupported DB driver '{$driver}'."),
        };

        return $sqlPath;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    protected function dumpMysql(array $cfg, string $sqlPath): void
    {
        $bin = (string) config('backup.binaries.mysqldump', 'mysqldump');

        if (! $this->binaryExists($bin)) {
            $this->writeDevPlaceholder($sqlPath, "mysqldump binary '{$bin}' not found on PATH");
            return;
        }

        $host     = (string) ($cfg['host'] ?? '127.0.0.1');
        $port     = (string) ($cfg['port'] ?? '3306');
        $database = (string) ($cfg['database'] ?? '');
        $user     = (string) ($cfg['username'] ?? '');
        $password = (string) ($cfg['password'] ?? '');

        if ($database === '') {
            throw new RuntimeException('BackupService: empty DB_DATABASE for mysql connection.');
        }

        // Use a defaults-extra-file to avoid leaking the password on argv (visible
        // in `ps aux`). Generated/ deleted on the fly per dump.
        $defaultsFile = tempnam(sys_get_temp_dir(), 'flik-mysqldump-');
        if ($defaultsFile === false) {
            throw new RuntimeException('BackupService: failed to allocate mysqldump credentials tempfile.');
        }

        try {
            file_put_contents(
                $defaultsFile,
                "[client]\nuser=" . $user . "\npassword=\"" . $this->escapeIniValue($password) . "\"\n",
            );
            chmod($defaultsFile, 0600);

            $cmd = [
                $bin,
                '--defaults-extra-file=' . $defaultsFile,
                '--host=' . $host,
                '--port=' . $port,
                '--single-transaction',
                '--quick',
                '--lock-tables=false',
                '--no-tablespaces',
                '--routines',
                '--triggers',
                '--events',
                '--default-character-set=utf8mb4',
                '--add-drop-table',
                '--set-gtid-purged=OFF',
                $database,
            ];

            $this->runToFile($cmd, $sqlPath, 'mysqldump');
        } finally {
            @unlink($defaultsFile);
        }
    }

    /**
     * @param array<string, mixed> $cfg
     */
    protected function dumpPostgres(array $cfg, string $sqlPath): void
    {
        $bin = (string) config('backup.binaries.pg_dump', 'pg_dump');

        if (! $this->binaryExists($bin)) {
            $this->writeDevPlaceholder($sqlPath, "pg_dump binary '{$bin}' not found on PATH");
            return;
        }

        $database = (string) ($cfg['database'] ?? '');
        if ($database === '') {
            throw new RuntimeException('BackupService: empty DB_DATABASE for pgsql connection.');
        }

        $env = [
            'PGHOST'     => (string) ($cfg['host'] ?? '127.0.0.1'),
            'PGPORT'     => (string) ($cfg['port'] ?? '5432'),
            'PGUSER'     => (string) ($cfg['username'] ?? ''),
            'PGPASSWORD' => (string) ($cfg['password'] ?? ''),
        ];

        $cmd = [
            $bin,
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--format=plain',
            $database,
        ];

        $this->runToFile($cmd, $sqlPath, 'pg_dump', $env);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    protected function dumpSqlite(array $cfg, string $sqlPath): void
    {
        $database = (string) ($cfg['database'] ?? '');

        if ($database === '' || ! is_file($database)) {
            $this->writeDevPlaceholder($sqlPath, "sqlite database file '{$database}' not found");
            return;
        }

        // No external binary required — SQLite is a single file. Copy it and
        // include it as a binary blob; restore is `cp <file> <database>`.
        if (! copy($database, $sqlPath . '.sqlite')) {
            throw new RuntimeException('BackupService: failed to copy sqlite db file.');
        }

        file_put_contents(
            $sqlPath,
            "-- FLiK SQLite backup\n-- Restore: copy " . basename($sqlPath) . ".sqlite over the live DB file.\n",
        );
    }

    // ---------------------------------------------------------------------
    // Step 2 — Archive (tar + gzip)
    // ---------------------------------------------------------------------

    /**
     * Bundle the SQL dump plus optional media directories into a .tar.gz.
     *
     * Media directories that exceed `max_media_bytes_per_backup` (per dir)
     * are skipped with a warning — only the SQL dump is included for that
     * dir.
     *
     * @param array<int, string> $extraDirs Paths relative to base_path() (or absolute).
     * @return string Absolute path to the produced .tar.gz file.
     */
    public function archive(string $sqlPath, array $extraDirs = ['storage/app/public/movies']): string
    {
        if (! is_file($sqlPath)) {
            throw new RuntimeException("BackupService::archive — SQL file not found: {$sqlPath}");
        }

        $tarPath = preg_replace('/\.sql$/i', '.tar.gz', $sqlPath) ?? ($sqlPath . '.tar.gz');
        if ($tarPath === $sqlPath) {
            $tarPath .= '.tar.gz';
        }

        $maxBytes = (int) config('backup.max_media_bytes_per_backup', 5_000_000_000);

        $included = [
            // Always include the SQL dump (basename only — we cd into its dir).
            basename($sqlPath),
        ];

        // Include the .sqlite sibling if dumpSqlite() produced one.
        if (is_file($sqlPath . '.sqlite')) {
            $included[] = basename($sqlPath) . '.sqlite';
        }

        $sqlDir = dirname($sqlPath);

        // Stage media dirs as symlinks (or copies on Windows) inside the archive
        // working directory so the tar layout is predictable on restore.
        $stagedExtras = $this->stageExtras($sqlDir, $extraDirs, $maxBytes);
        foreach ($stagedExtras as $rel) {
            $included[] = $rel;
        }

        try {
            $this->buildTarGz($sqlDir, $included, $tarPath);
        } finally {
            // Clean up any symlinks/copies we staged into the working dir.
            foreach ($stagedExtras as $rel) {
                $abs = $sqlDir . DIRECTORY_SEPARATOR . $rel;
                if (is_link($abs) || is_file($abs)) {
                    @unlink($abs);
                } elseif (is_dir($abs)) {
                    $this->rmTree($abs);
                }
            }
        }

        return $tarPath;
    }

    // ---------------------------------------------------------------------
    // Step 3 — Encrypt (AES-256-CBC + PBKDF2)
    // ---------------------------------------------------------------------

    /**
     * Encrypt the archive with AES-256-CBC, PBKDF2 key derivation.
     *
     * Output is bit-for-bit compatible with `openssl enc -aes-256-cbc -pbkdf2 -salt`,
     * so operators can decrypt manually with stock OpenSSL if our tooling is
     * unavailable:
     *
     *   openssl enc -d -aes-256-cbc -pbkdf2 -in db.tar.gz.enc -out db.tar.gz
     *
     * Implementation prefers the `openssl` CLI via proc_open when configured
     * (faster on large files). Falls back to chunked openssl_encrypt() PHP
     * implementation when the binary is unavailable.
     *
     * @return string Absolute path to the produced .tar.gz.enc file.
     */
    public function encrypt(string $tarPath): string
    {
        if (! is_file($tarPath)) {
            throw new RuntimeException("BackupService::encrypt — archive not found: {$tarPath}");
        }

        $key = (string) config('backup.encryption_key', '');
        if ($key === '') {
            throw new RuntimeException(
                'BackupService::encrypt — BACKUP_ENCRYPTION_KEY is not set. '
                . 'Generate one with: php artisan key:generate --show'
            );
        }

        $encPath = $tarPath . '.enc';

        $useBinary = (bool) config('backup.prefer_openssl_binary', true)
            && $this->binaryExists((string) config('backup.binaries.openssl', 'openssl'));

        if ($useBinary) {
            $this->encryptViaBinary($tarPath, $encPath, $key);
        } else {
            $this->encryptViaPhp($tarPath, $encPath, $key);
        }

        if (! is_file($encPath) || filesize($encPath) === 0) {
            throw new RuntimeException("BackupService::encrypt — output file empty/missing: {$encPath}");
        }

        return $encPath;
    }

    protected function encryptViaBinary(string $tarPath, string $encPath, string $key): void
    {
        $bin = (string) config('backup.binaries.openssl', 'openssl');

        // Pass the key via -pass env: so it never appears on argv / ps output.
        $cmd = [
            $bin, 'enc', '-aes-256-cbc', '-pbkdf2',
            '-iter', (string) self::PBKDF2_ITERATIONS,
            '-salt',
            '-in',  $tarPath,
            '-out', $encPath,
            '-pass', 'env:FLIK_BACKUP_KEY',
        ];

        $env = ['FLIK_BACKUP_KEY' => $key] + $this->inheritEnv();

        $this->runProcess($cmd, 'openssl-encrypt', $env);
    }

    /**
     * Pure-PHP equivalent of `openssl enc -aes-256-cbc -pbkdf2 -salt`.
     * Streams in 1 MiB chunks to keep memory bounded for multi-GB archives.
     */
    protected function encryptViaPhp(string $tarPath, string $encPath, string $key): void
    {
        $salt = random_bytes(8);
        // OpenSSL EVP_BytesToKey replacement: derive 48 bytes (32 key + 16 IV).
        $derived = hash_pbkdf2('sha256', $key, $salt, self::PBKDF2_ITERATIONS, 48, true);
        $cipherKey = substr($derived, 0, 32);
        $iv        = substr($derived, 32, 16);

        $in  = fopen($tarPath, 'rb');
        $out = fopen($encPath, 'wb');

        if ($in === false || $out === false) {
            if ($in !== false)  { fclose($in); }
            if ($out !== false) { fclose($out); }
            throw new RuntimeException('BackupService::encrypt — failed to open file streams.');
        }

        try {
            // OpenSSL "Salted__" magic header (8 bytes) + 8-byte salt = 16 bytes prefix.
            fwrite($out, self::OPENSSL_MAGIC . $salt);

            // Block-aligned CBC: read fixed-size chunks, encrypt with NO_PADDING,
            // then encrypt the trailing remainder once with default padding.
            // Reading in multiples of 16 keeps NO_PADDING happy.
            $chunkSize = self::ENCRYPT_CHUNK_BYTES; // 1 MiB, multiple of 16.
            $carry = '';

            while (! feof($in)) {
                $buf = fread($in, $chunkSize);
                if ($buf === false) {
                    throw new RuntimeException('BackupService::encrypt — read error mid-stream.');
                }
                $carry .= $buf;

                // Encrypt full 16-byte blocks; keep the unaligned tail in $carry.
                $blockBytes = intdiv(strlen($carry), 16) * 16;
                if ($blockBytes >= $chunkSize) {
                    $head = substr($carry, 0, $blockBytes);
                    $carry = substr($carry, $blockBytes);

                    $cipher = openssl_encrypt(
                        $head,
                        self::CIPHER,
                        $cipherKey,
                        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                        $iv,
                    );
                    if ($cipher === false) {
                        throw new RuntimeException('BackupService::encrypt — openssl_encrypt failed mid-stream.');
                    }
                    fwrite($out, $cipher);

                    // Advance the IV to the last ciphertext block (CBC chaining).
                    $iv = substr($cipher, -16);
                }
            }

            // Final block with PKCS#7 padding (no ZERO_PADDING flag).
            $finalCipher = openssl_encrypt(
                $carry,
                self::CIPHER,
                $cipherKey,
                OPENSSL_RAW_DATA,
                $iv,
            );
            if ($finalCipher === false) {
                throw new RuntimeException('BackupService::encrypt — openssl_encrypt failed on final block.');
            }
            fwrite($out, $finalCipher);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    // ---------------------------------------------------------------------
    // Step 4 — Upload to CDN-backed remote disk
    // ---------------------------------------------------------------------

    /**
     * Upload an encrypted backup to a CDN-backed remote disk.
     *
     * @return string Remote object path (e.g. "backups/2026-05-13/db_….tar.gz.enc").
     */
    public function upload(string $encPath, string $disk = 'bunny'): string
    {
        if (! is_file($encPath)) {
            throw new RuntimeException("BackupService::upload — encrypted file not found: {$encPath}");
        }

        $remote = $this->resolveRemote($disk);

        $remotePath = sprintf(
            'backups/%s/%s',
            CarbonImmutable::now()->format('Y-m-d'),
            basename($encPath),
        );

        $stream = fopen($encPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("BackupService::upload — failed to open {$encPath} for streaming.");
        }

        try {
            $ok = $remote->putStream($remotePath, $stream);
        } finally {
            fclose($stream);
        }

        if (! $ok) {
            throw new RuntimeException("BackupService::upload — remote disk '{$disk}' rejected the upload.");
        }

        return $remotePath;
    }

    // ---------------------------------------------------------------------
    // Step 5 — Prune old backups
    // ---------------------------------------------------------------------

    /**
     * Delete local + remote backups older than `keepDays` days.
     *
     * @return array{local_deleted: int, remote_deleted: int, errors: array<int,string>}
     */
    public function prune(int $keepDays = 30): array
    {
        $cutoff = CarbonImmutable::now()->subDays(max(1, $keepDays));
        $report = ['local_deleted' => 0, 'remote_deleted' => 0, 'errors' => []];

        // Local prune ----------------------------------------------------
        $localDir = $this->ensureLocalBackupDir();
        foreach ((array) glob($localDir . DIRECTORY_SEPARATOR . 'db_*') as $path) {
            if (! is_string($path) || ! file_exists($path)) {
                continue;
            }
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff->getTimestamp()) {
                if (is_dir($path)) {
                    $this->rmTree($path);
                } elseif (! @unlink($path)) {
                    $report['errors'][] = "local prune failed: {$path}";
                    continue;
                }
                $report['local_deleted']++;
            }
        }

        // Remote prune ---------------------------------------------------
        $diskName = (string) config('backup.remote_disk', 'bunny');
        try {
            $remote = $this->resolveRemote($diskName);
            $report['remote_deleted'] = $this->pruneRemote($remote, $cutoff);
        } catch (\Throwable $e) {
            $report['errors'][] = "remote prune failed ({$diskName}): " . $e->getMessage();
        }

        return $report;
    }

    /**
     * Best-effort remote prune. Walks `backups/YYYY-MM-DD/` directories that
     * sort lexicographically before the cutoff and deletes their contents.
     */
    protected function pruneRemote(CdnStorageContract $remote, CarbonImmutable $cutoff): int
    {
        // Only BunnyStorageService exposes listFiles() — guard for the contract.
        if (! method_exists($remote, 'listFiles')) {
            Log::info('BackupService::prune — remote disk does not expose listFiles(); skipping remote prune.');
            return 0;
        }

        $deleted = 0;
        $cutoffDate = $cutoff->format('Y-m-d');

        /** @var array<int, array<string, mixed>> $dirs */
        $dirs = $remote->listFiles('backups');
        foreach ($dirs as $entry) {
            $name = (string) ($entry['ObjectName'] ?? '');
            $isDir = (bool) ($entry['IsDirectory'] ?? false);
            if (! $isDir || $name === '' || $name >= $cutoffDate) {
                continue;
            }

            /** @var array<int, array<string, mixed>> $files */
            $files = $remote->listFiles("backups/{$name}");
            foreach ($files as $file) {
                $fileName = (string) ($file['ObjectName'] ?? '');
                if ($fileName === '') {
                    continue;
                }
                if ($remote->delete("backups/{$name}/{$fileName}")) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    // ---------------------------------------------------------------------
    // Decryption (used by RestoreBackup command)
    // ---------------------------------------------------------------------

    /**
     * Inverse of {@see encrypt()} — restore a `.tar.gz.enc` to plaintext
     * `.tar.gz`. Same OpenSSL Salted__ + PBKDF2 + AES-256-CBC format.
     *
     * @return string Absolute path to the produced .tar.gz file.
     */
    public function decrypt(string $encPath, ?string $outPath = null): string
    {
        if (! is_file($encPath)) {
            throw new RuntimeException("BackupService::decrypt — file not found: {$encPath}");
        }

        $key = (string) config('backup.encryption_key', '');
        if ($key === '') {
            throw new RuntimeException('BackupService::decrypt — BACKUP_ENCRYPTION_KEY is not set.');
        }

        $outPath ??= preg_replace('/\.enc$/', '', $encPath) ?: ($encPath . '.dec');

        $useBinary = (bool) config('backup.prefer_openssl_binary', true)
            && $this->binaryExists((string) config('backup.binaries.openssl', 'openssl'));

        if ($useBinary) {
            $bin = (string) config('backup.binaries.openssl', 'openssl');
            $cmd = [
                $bin, 'enc', '-d', '-aes-256-cbc', '-pbkdf2',
                '-iter', (string) self::PBKDF2_ITERATIONS,
                '-in', $encPath, '-out', $outPath,
                '-pass', 'env:FLIK_BACKUP_KEY',
            ];
            $env = ['FLIK_BACKUP_KEY' => $key] + $this->inheritEnv();
            $this->runProcess($cmd, 'openssl-decrypt', $env);
            return $outPath;
        }

        // Pure-PHP decrypt path.
        $in = fopen($encPath, 'rb');
        if ($in === false) {
            throw new RuntimeException("BackupService::decrypt — cannot open {$encPath}.");
        }
        try {
            $magic = fread($in, 8);
            $salt  = fread($in, 8);
            if ($magic !== self::OPENSSL_MAGIC || $salt === false || strlen($salt) !== 8) {
                throw new RuntimeException('BackupService::decrypt — file is not openssl Salted__ format.');
            }

            $derived = hash_pbkdf2('sha256', $key, $salt, self::PBKDF2_ITERATIONS, 48, true);
            $cipherKey = substr($derived, 0, 32);
            $iv = substr($derived, 32, 16);

            $cipher = stream_get_contents($in);
            if ($cipher === false) {
                throw new RuntimeException('BackupService::decrypt — read failed.');
            }

            $plain = openssl_decrypt($cipher, self::CIPHER, $cipherKey, OPENSSL_RAW_DATA, $iv);
            if ($plain === false) {
                throw new RuntimeException('BackupService::decrypt — openssl_decrypt failed (wrong key?).');
            }

            file_put_contents($outPath, $plain);
        } finally {
            fclose($in);
        }

        return $outPath;
    }

    /**
     * Resolve a CdnStorageContract by short name. Throws on unknown disk.
     */
    public function resolveRemote(string $disk): CdnStorageContract
    {
        return match ($disk) {
            'bunny' => app(BunnyStorageService::class),
            's3'    => app(S3StorageService::class),
            default => throw new RuntimeException("BackupService: unknown remote disk '{$disk}'."),
        };
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Stage the requested extra directories into the archive working dir as
     * relative entries. Returns the list of staged paths (relative to $workDir).
     *
     * Skips dirs that exceed $maxBytes (per-dir budget) and logs a warning.
     *
     * @param array<int, string> $extras
     * @return array<int, string>
     */
    protected function stageExtras(string $workDir, array $extras, int $maxBytes): array
    {
        $staged = [];

        foreach ($extras as $rel) {
            $abs = $this->absolutize($rel);

            if (! is_dir($abs) && ! is_file($abs)) {
                Log::info("BackupService::archive — extra path missing, skipping: {$abs}");
                continue;
            }

            $size = is_dir($abs) ? $this->dirSize($abs) : (int) (@filesize($abs) ?: 0);
            if ($size > $maxBytes) {
                Log::warning("BackupService::archive — skipping '{$abs}' ({$size} bytes > cap {$maxBytes}).");
                continue;
            }

            $stageName = 'extra_' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $rel);
            $stageAbs  = $workDir . DIRECTORY_SEPARATOR . $stageName;

            // Symlink on POSIX, recursive copy on Windows where symlinks need privs.
            $linked = false;
            if (function_exists('symlink')) {
                $linked = @symlink($abs, $stageAbs);
            }
            if (! $linked) {
                if (is_dir($abs)) {
                    $this->copyTree($abs, $stageAbs);
                } else {
                    @copy($abs, $stageAbs);
                }
            }

            $staged[] = $stageName;
        }

        return $staged;
    }

    /**
     * Build a tar.gz of $items (each relative to $workDir) at $outPath.
     *
     * Prefers GNU tar via proc_open. Falls back to a PHP PharData stream so
     * dev environments without tar (Windows) still work.
     */
    protected function buildTarGz(string $workDir, array $items, string $outPath): void
    {
        $tarBin = (string) config('backup.binaries.tar', 'tar');

        if ($this->binaryExists($tarBin)) {
            $cmd = array_merge(
                [$tarBin, '-czf', $outPath, '-C', $workDir, '--dereference'],
                $items,
            );
            $this->runProcess($cmd, 'tar');
            return;
        }

        // PHP fallback (no tar binary). PharData writes uncompressed .tar then
        // we gzip it via php streams.
        Log::info('BackupService::archive — tar binary missing, using PharData fallback.');

        $tmpTar = $outPath . '.tmp.tar';
        $phar = new \PharData($tmpTar);

        foreach ($items as $item) {
            $abs = $workDir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($abs)) {
                $phar->buildFromDirectory($abs);
            } elseif (is_file($abs)) {
                $phar->addFile($abs, $item);
            }
        }
        unset($phar);

        $in  = fopen($tmpTar, 'rb');
        $out = gzopen($outPath, 'wb9');
        if ($in === false || $out === false) {
            throw new RuntimeException('BackupService::archive — gzip stream open failed.');
        }
        while (! feof($in)) {
            $buf = fread($in, 65536);
            if ($buf === false) {
                break;
            }
            gzwrite($out, $buf);
        }
        fclose($in);
        gzclose($out);
        @unlink($tmpTar);
    }

    /**
     * Run a process and stream stdout into $outFile. Throws on non-zero exit.
     *
     * @param array<int,string> $cmd
     * @param array<string,string>|null $env
     */
    protected function runToFile(array $cmd, string $outFile, string $label, ?array $env = null): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, null, $env ?? $this->inheritEnv());
        if (! is_resource($proc)) {
            throw new RuntimeException("BackupService: failed to spawn {$label} process.");
        }

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new RuntimeException(
                "BackupService: {$label} exited with status {$exit}: " . substr($stderr, 0, 1000)
            );
        }
    }

    /**
     * Run a process, discard stdout, raise on non-zero exit.
     *
     * @param array<int,string> $cmd
     * @param array<string,string>|null $env
     */
    protected function runProcess(array $cmd, string $label, ?array $env = null): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, null, $env ?? $this->inheritEnv());
        if (! is_resource($proc)) {
            throw new RuntimeException("BackupService: failed to spawn {$label} process.");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new RuntimeException(
                "BackupService: {$label} exited with status {$exit}. "
                . 'stderr=' . substr($stderr, 0, 500) . ' stdout=' . substr($stdout, 0, 500)
            );
        }
    }

    /**
     * @return array<string,string>
     */
    protected function inheritEnv(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'USER', 'TMP', 'TEMP', 'SystemRoot'] as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $env[$k] = $v;
            }
        }
        return $env;
    }

    protected function ensureLocalBackupDir(): string
    {
        $rel = trim((string) config('backup.local_path', 'backups'), '/\\');
        $abs = storage_path('app' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . $rel);
        if (! is_dir($abs) && ! mkdir($abs, 0750, true) && ! is_dir($abs)) {
            throw new RuntimeException("BackupService: failed to create backup dir: {$abs}");
        }
        return $abs;
    }

    protected function timestamp(): string
    {
        return CarbonImmutable::now()->format('Ymd_His');
    }

    protected function binaryExists(string $bin): bool
    {
        // Allow absolute paths.
        if (is_file($bin) && is_executable($bin)) {
            return true;
        }
        $isWindows = stripos(PHP_OS, 'WIN') === 0;
        $cmd = $isWindows ? "where {$bin}" : "command -v {$bin}";
        $proc = @proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (! is_resource($proc)) {
            return false;
        }
        $out = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return $exit === 0 && trim($out) !== '';
    }

    /**
     * In dev environments, write a placeholder .sql so the rest of the
     * pipeline (archive/encrypt/upload/prune) can be exercised end-to-end.
     */
    protected function writeDevPlaceholder(string $sqlPath, string $reason): void
    {
        Log::warning("BackupService::dump — {$reason}; writing placeholder .sql for dev mode.");

        file_put_contents(
            $sqlPath,
            "-- FLiK backup placeholder\n"
            . "-- Generated at: " . CarbonImmutable::now()->toIso8601String() . "\n"
            . "-- Reason: {$reason}\n"
            . "-- This file is NOT a real database dump. Install the dump binary to produce a real backup.\n"
        );
    }

    protected function escapeIniValue(string $v): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
    }

    protected function absolutize(string $path): string
    {
        if ($path === '') {
            return base_path();
        }
        // Already absolute (POSIX or Windows drive letter).
        if ($path[0] === '/' || (strlen($path) > 2 && $path[1] === ':')) {
            return $path;
        }
        return base_path($path);
    }

    protected function dirSize(string $dir): int
    {
        $bytes = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
        } catch (\Throwable) {
            // Permission errors / disappearing files — partial total is fine.
        }
        return $bytes;
    }

    protected function copyTree(string $src, string $dst): void
    {
        if (! is_dir($dst) && ! mkdir($dst, 0750, true) && ! is_dir($dst)) {
            throw new RuntimeException("BackupService: failed to create {$dst}");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $target = $dst . DIRECTORY_SEPARATOR . $rel;
            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0750, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    protected function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
