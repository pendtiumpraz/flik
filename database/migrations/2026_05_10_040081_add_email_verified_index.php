<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index `users.email_verified_at` so the `verified` middleware lookups and any
 * dashboard counts (verified-vs-unverified, churn signals, etc.) stay sub-ms
 * once the table grows. The column itself was created back in the original
 * Laravel users-table migration (2014_10_12_000000), so we only add the index.
 */
return new class extends Migration
{
    private const INDEX_NAME = 'users_email_verified_at_index';

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'email_verified_at')) {
            // Defensive: in case someone runs this on a stripped-down schema.
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            });
        }

        if (! $this->indexExists('users', self::INDEX_NAME)) {
            Schema::table('users', function (Blueprint $table): void {
                $table->index('email_verified_at', self::INDEX_NAME);
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('users', self::INDEX_NAME)) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX_NAME);
            });
        }
    }

    /**
     * Driver-agnostic index existence check (MySQL / PostgreSQL / SQLite).
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();
        $prefix = DB::connection()->getTablePrefix();
        $prefixedTable = $prefix.$table;

        return match ($driver) {
            'mysql', 'mariadb' => DB::selectOne(
                'SELECT 1 AS hit FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                [$prefixedTable, $indexName]
            ) !== null,
            'pgsql' => DB::selectOne(
                'SELECT 1 AS hit FROM pg_indexes WHERE tablename = ? AND indexname = ? LIMIT 1',
                [$prefixedTable, $indexName]
            ) !== null,
            'sqlite' => DB::selectOne(
                "SELECT 1 AS hit FROM sqlite_master WHERE type = 'index' AND name = ? LIMIT 1",
                [$indexName]
            ) !== null,
            default => false,
        };
    }
};
