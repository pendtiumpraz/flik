<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnownDevice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ReencryptPii
 * --------------------------------------------------------------------------
 * Re-encrypts every PII column listed in docs/security/pii-encryption.md
 * under the *current* APP_KEY, optionally migrating from a previous key.
 *
 * Use cases:
 *   1. **Initial rollout** — migration 2026_05_10_040100 added encrypted
 *      columns. Existing rows hold plaintext. Run this command once with
 *      no extra env to convert them to ciphertext.
 *   2. **Key rotation** — set OLD_APP_KEY in `.env`, deploy a new APP_KEY,
 *      run this command. Each row is decrypted with the old key and
 *      re-saved (so it lands re-encrypted with the new one). Then remove
 *      OLD_APP_KEY.
 *
 * Behaviour:
 *   - For each (model, column) target, iterate in chunks of 500.
 *   - For each row: read raw DB value, attempt decrypt with OLD_APP_KEY (if
 *     set), then with current APP_KEY, then fall back to "treat as plaintext".
 *   - Write through the model so the `encrypted` cast re-encrypts under
 *     the current key.
 *
 * Safety:
 *   - `--dry` does everything except the final save.
 *   - Touches `updated_at` on rewrite. If you DO NOT want this side effect,
 *     pass `--no-touch` (uses raw DB updates and skips Eloquent events).
 *   - Per-row failures are logged via Log::warning and do NOT abort the run.
 *
 * Usage:
 *   php artisan flik:security:reencrypt-pii
 *   php artisan flik:security:reencrypt-pii --dry
 *   php artisan flik:security:reencrypt-pii --only=users
 *   php artisan flik:security:reencrypt-pii --only=users,subscriptions
 *   php artisan flik:security:reencrypt-pii --no-touch
 *   OLD_APP_KEY=base64:... php artisan flik:security:reencrypt-pii  (rotation)
 */
class ReencryptPii extends Command
{
    protected $signature = 'flik:security:reencrypt-pii
                            {--dry : Print what would be re-encrypted; do not write.}
                            {--only= : Comma-separated subset of targets to process (users, subscriptions, known_devices).}
                            {--no-touch : Skip Eloquent events / updated_at bumps; use raw DB updates.}
                            {--chunk=500 : Chunk size when iterating rows.}';

    protected $description = 'Re-encrypt PII columns under the current APP_KEY (initial rollout or key rotation).';

    /**
     * Targets: model class + columns that are cast to `encrypted` (or
     * `encrypted:array`). Keep in sync with docs/security/pii-encryption.md.
     *
     * @var array<string, array{model: class-string<Model>, columns: array<int, string>}>
     */
    private array $targets = [
        'users' => [
            'model'   => User::class,
            'columns' => ['phone', 'address'],
        ],
        'subscriptions' => [
            'model'   => Subscription::class,
            'columns' => ['billing_address'],
        ],
        'known_devices' => [
            'model'   => KnownDevice::class,
            'columns' => ['ip'],
        ],
    ];

    public function handle(): int
    {
        $oldEncrypter = $this->buildOldEncrypter();
        if ($oldEncrypter !== null) {
            $this->info('OLD_APP_KEY detected — key rotation mode.');
        } else {
            $this->info('No OLD_APP_KEY — initial rollout mode (will tolerate plaintext rows).');
        }

        $only = $this->resolveOnly();
        $dry = (bool) $this->option('dry');
        $noTouch = (bool) $this->option('no-touch');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $totals = [
            'inspected'    => 0,
            'rewritten'    => 0,
            'plaintext'    => 0,
            'rotated'      => 0,
            'unchanged'    => 0,
            'failed'       => 0,
        ];

        foreach ($this->targets as $name => $target) {
            if (! in_array($name, $only, true)) {
                continue;
            }

            /** @var class-string<Model> $modelClass */
            $modelClass = $target['model'];
            $columns = $target['columns'];

            $instance = new $modelClass();
            $table = $instance->getTable();

            // Skip targets whose table or columns are missing — the migration
            // may not have run on this environment yet.
            if (! Schema::hasTable($table)) {
                $this->warn("Skipping {$name}: table `{$table}` does not exist.");
                continue;
            }
            $existingCols = array_values(array_filter(
                $columns,
                fn (string $c): bool => Schema::hasColumn($table, $c),
            ));
            if ($existingCols === []) {
                $this->warn("Skipping {$name}: none of the configured columns exist on `{$table}`.");
                continue;
            }

            $this->line("Processing {$name} ({$table}) — columns: " . implode(', ', $existingCols));

            $modelClass::query()
                ->select(array_merge([$instance->getKeyName()], $existingCols))
                ->orderBy($instance->getKeyName())
                ->chunkById($chunkSize, function ($rows) use ($modelClass, $existingCols, $instance, $oldEncrypter, $dry, $noTouch, &$totals): void {
                    foreach ($rows as $row) {
                        $totals['inspected']++;

                        $updates = [];
                        $rowChanged = false;

                        foreach ($existingCols as $col) {
                            $raw = $row->getAttributes()[$col] ?? null; // bypass casts — get the on-disk string
                            if ($raw === null || $raw === '') {
                                continue;
                            }

                            try {
                                [$plain, $kind] = $this->decryptAny($raw, $oldEncrypter);
                            } catch (Throwable $e) {
                                Log::warning('flik:security:reencrypt-pii decrypt failed', [
                                    'table'  => $row->getTable(),
                                    'pk'     => $row->getKey(),
                                    'column' => $col,
                                    'error'  => $e->getMessage(),
                                ]);
                                $totals['failed']++;
                                continue;
                            }

                            // Re-encrypt under the current key.
                            $newCipher = Crypt::encryptString($plain);

                            if ($newCipher === $raw) {
                                // Identical (cipher block init makes this practically impossible
                                // for non-trivial values, but check anyway).
                                $totals['unchanged']++;
                                continue;
                            }

                            $updates[$col] = $newCipher;
                            $rowChanged = true;

                            if ($kind === 'plaintext') {
                                $totals['plaintext']++;
                            } elseif ($kind === 'old-key') {
                                $totals['rotated']++;
                            } else {
                                $totals['rewritten']++;
                            }
                        }

                        if (! $rowChanged) {
                            continue;
                        }

                        if ($dry) {
                            $this->line(sprintf('  [dry] would update %s#%s (%s)',
                                class_basename($modelClass),
                                (string) $row->getKey(),
                                implode(', ', array_keys($updates)),
                            ));
                            continue;
                        }

                        try {
                            // We always write via raw DB to avoid double-encryption
                            // (the `encrypted` cast would re-encrypt our already-
                            // encrypted ciphertext on the way out via Eloquent save).
                            // The --no-touch option additionally skips updated_at.
                            $payload = $updates;
                            if (! $noTouch && Schema::hasColumn($row->getTable(), 'updated_at')) {
                                $payload['updated_at'] = now();
                            }
                            DB::table($row->getTable())
                                ->where($instance->getKeyName(), $row->getKey())
                                ->update($payload);
                        } catch (Throwable $e) {
                            $totals['failed']++;
                            Log::error('flik:security:reencrypt-pii write failed', [
                                'table' => $row->getTable(),
                                'pk'    => $row->getKey(),
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });
        }

        $this->newLine();
        $this->info('=== Done ===');
        foreach ($totals as $label => $count) {
            $this->line(sprintf('  %-12s %d', $label, $count));
        }

        return self::SUCCESS;
    }

    /**
     * Build an Encrypter instance keyed by OLD_APP_KEY (for rotation), or
     * return null if rotation is not configured.
     */
    private function buildOldEncrypter(): ?Encrypter
    {
        $oldKey = (string) env('OLD_APP_KEY', '');
        if ($oldKey === '') {
            return null;
        }

        if (str_starts_with($oldKey, 'base64:')) {
            $oldKey = base64_decode(substr($oldKey, 7), true);
            if ($oldKey === false) {
                $this->warn('OLD_APP_KEY: base64 decode failed — ignoring.');
                return null;
            }
        }

        $cipher = (string) config('app.cipher', 'AES-256-CBC');

        try {
            return new Encrypter($oldKey, $cipher);
        } catch (Throwable $e) {
            $this->warn('OLD_APP_KEY: could not build Encrypter (' . $e->getMessage() . ') — ignoring.');
            return null;
        }
    }

    /**
     * Try old key, then current key, then plaintext. Returns
     * [plaintext_value, kind] where kind ∈ {'old-key', 'current-key', 'plaintext'}.
     *
     * @return array{0:string,1:string}
     */
    private function decryptAny(string $raw, ?Encrypter $oldEncrypter): array
    {
        if ($oldEncrypter !== null) {
            try {
                return [$oldEncrypter->decryptString($raw), 'old-key'];
            } catch (Throwable) {
                // fall through
            }
        }

        try {
            return [Crypt::decryptString($raw), 'current-key'];
        } catch (Throwable) {
            // fall through
        }

        // Treat as plaintext.
        return [$raw, 'plaintext'];
    }

    /**
     * @return array<int,string>
     */
    private function resolveOnly(): array
    {
        $only = (string) ($this->option('only') ?? '');
        if ($only === '') {
            return array_keys($this->targets);
        }
        $picked = array_values(array_filter(array_map('trim', explode(',', $only))));
        $unknown = array_diff($picked, array_keys($this->targets));
        if ($unknown !== []) {
            $this->warn('Unknown --only target(s) ignored: ' . implode(', ', $unknown));
        }
        return array_values(array_intersect($picked, array_keys($this->targets)));
    }
}
