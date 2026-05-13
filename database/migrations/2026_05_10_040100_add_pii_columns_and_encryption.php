<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add PII columns and convert existing PII string columns to TEXT so
 * Laravel's `encrypted` cast (AES-256-CBC, base64-wrapped, ~3.5x growth)
 * fits without truncation.
 *
 * Columns added (when missing):
 *   - users.phone               TEXT NULL    (encrypted at app layer)
 *   - users.address             TEXT NULL    (encrypted at app layer)
 *   - users.national_id_hash    TEXT NULL    (sha256 + pepper — for lookup, NOT encrypted)
 *   - users.birth_date          DATE NULL    (kept plaintext — needed for age verification queries)
 *
 *   - subscriptions.billing_address  TEXT NULL  (encrypted at app layer)
 *
 *   - known_devices.ip               TEXT  → migrated from existing string(45),
 *                                            because encrypted IPv6 won't fit in 45 chars
 *
 * Notes:
 *   - We deliberately do NOT touch users.email or users.name. Those need to
 *     remain queryable (login, search, name display in admin UI) and breaking
 *     them would break authentication.
 *   - Existing rows with plaintext values must be re-encrypted via
 *     `php artisan flik:security:reencrypt-pii` after this migration runs.
 *     The command tolerates plaintext-on-disk rows.
 *   - We add an index on national_id_hash to make lookup-by-hash O(log n).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->text('phone')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'address')) {
                $table->text('address')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'national_id_hash')) {
                // sha256 hex = 64 chars; TEXT keeps us flexible if we ever
                // switch the hash algo (e.g. argon2id-derived).
                $table->text('national_id_hash')->nullable()->after('address');
            }
            if (! Schema::hasColumn('users', 'birth_date')) {
                // Plaintext date — used by KYC / age-gate queries
                // (`->where('birth_date', '<', $cutoff)`).
                $table->date('birth_date')->nullable()->after('national_id_hash');
            }
        });

        // Index for searchable hash lookup. Wrapped in try/catch in case the
        // migration is re-run on a DB where the column already had this index.
        try {
            Schema::table('users', function (Blueprint $table) {
                // Use a hash-prefix index since some MySQL versions choke on
                // full TEXT indexes. 64 chars covers the full sha256 digest.
                $table->index([\Illuminate\Support\Facades\DB::raw('national_id_hash(64)')], 'users_national_id_hash_index');
            });
        } catch (\Throwable) {
            // Driver-specific (SQLite ignores prefix) — fall back to a plain index.
            try {
                Schema::table('users', function (Blueprint $table) {
                    $table->index('national_id_hash', 'users_national_id_hash_index');
                });
            } catch (\Throwable) {
                // Already exists — fine.
            }
        }

        if (Schema::hasTable('subscriptions') && ! Schema::hasColumn('subscriptions', 'billing_address')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->text('billing_address')->nullable()->after('amount');
            });
        }

        // known_devices.ip was string(45). Encrypted output is ~150+ chars,
        // so promote to TEXT. Use a temp column dance only if the current
        // type is too small.
        if (Schema::hasTable('known_devices') && Schema::hasColumn('known_devices', 'ip')) {
            try {
                Schema::table('known_devices', function (Blueprint $table) {
                    $table->text('ip')->change();
                });
            } catch (\Throwable) {
                // doctrine/dbal may not be installed; fall back to add+copy+drop.
                Schema::table('known_devices', function (Blueprint $table) {
                    if (! Schema::hasColumn('known_devices', 'ip_text')) {
                        $table->text('ip_text')->nullable()->after('ip');
                    }
                });
                \Illuminate\Support\Facades\DB::statement('UPDATE known_devices SET ip_text = ip WHERE ip_text IS NULL');
                Schema::table('known_devices', function (Blueprint $table) {
                    $table->dropColumn('ip');
                });
                Schema::table('known_devices', function (Blueprint $table) {
                    $table->renameColumn('ip_text', 'ip');
                });
            }
        }

        // Payment table: encrypted last4 column. Only touch if the table
        // exists (Midtrans currently keeps PAN; we may add a local payments
        // table later for non-Midtrans rails).
        if (Schema::hasTable('payments') && ! Schema::hasColumn('payments', 'last4_card_digits')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->text('last4_card_digits')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            try {
                $table->dropIndex('users_national_id_hash_index');
            } catch (\Throwable) {
                // ignore
            }
            foreach (['phone', 'address', 'national_id_hash', 'birth_date'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'billing_address')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('billing_address');
            });
        }

        // We intentionally do NOT shrink known_devices.ip back to string(45)
        // on rollback — that would truncate any encrypted blobs already
        // stored. Operators must rebuild the table manually if they really
        // want the old shape.

        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'last4_card_digits')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('last4_card_digits');
            });
        }
    }
};
