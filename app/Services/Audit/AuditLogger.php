<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Events\SecurityEventLogged;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AuditLogger
 * --------------------------------------------------------------------------
 * Persists rows to `audit_logs`. Two entrypoints:
 *
 *   - {@see self::log()}      — generic application audit (movie uploaded,
 *                                subscription created, etc.).
 *   - {@see self::security()} — same shape, but flags the row with
 *                                `is_security = true` AND fans out to
 *                                {@see \App\Services\Security\SecurityAlertService}
 *                                via the {@see SecurityEventLogged} event so
 *                                Slack/Discord can be notified for high-
 *                                severity items.
 *
 * Both methods are forward-compatible with the pre-`is_security` schema:
 * if the column is missing (e.g. running against an older migration set
 * during a partial deploy) the row is still written — the security flag
 * is silently dropped instead of throwing.
 *
 * Existing call sites keep working unchanged: {@see self::log()} retains
 * its original signature, and {@see self::security()} uses the same
 * `(action, subject, meta, user)` argument order, so callers never have
 * to memorise a different convention per method.
 */
class AuditLogger
{
    /**
     * Memoised result of "does the audit_logs table have an is_security
     * column?" — avoids a `Schema::hasColumn()` query on every log call.
     * Reset by tests via {@see self::resetSchemaCache()}.
     */
    private static ?bool $hasIsSecurityColumn = null;

    /**
     * Persist an audit log entry.
     *
     * - `$user` defaults to the currently authenticated user (if any).
     * - Request context (IP, user agent) is auto-filled when running inside
     *   an HTTP request; falls back to null in CLI/queue contexts.
     *
     * @param  string                                  $action  Namespaced verb, e.g. "movie.uploaded".
     * @param  \Illuminate\Database\Eloquent\Model|null $subject Affected entity, if any.
     * @param  array<string,mixed>                     $meta    Structured detail (before/after, amounts, etc.).
     * @param  \App\Models\User|null                   $user    Explicit actor (overrides auth user).
     */
    public function log(
        string $action,
        ?Model $subject = null,
        array $meta = [],
        ?User $user = null,
    ): AuditLog {
        return $this->persist(
            action: $action,
            subject: $subject,
            meta: $meta,
            user: $user,
            isSecurity: false,
        );
    }

    /**
     * Persist a SECURITY-flavoured audit log entry.
     *
     * Identical row shape to {@see self::log()} except:
     *   - `is_security` is set to true (when the column exists).
     *   - The {@see SecurityEventLogged} event is dispatched after the
     *     row is persisted so {@see \App\Listeners\PushSecurityAlerts}
     *     can fan out to Slack/Discord (severity-gated, throttled inside
     *     the listener / SecurityAlertService).
     *
     * Failures inside the event dispatch are swallowed — a notification
     * glitch must NEVER bubble up into the request that triggered the
     * security event (e.g. a failed login should still complete its
     * 401 response even if the Slack webhook is down).
     *
     * @param  string                                  $event   Pass a {@see \App\Support\SecurityEvents}::* constant.
     * @param  \Illuminate\Database\Eloquent\Model|null $subject Affected entity, if any.
     * @param  array<string,mixed>                     $meta    Structured detail.
     * @param  \App\Models\User|null                   $user    Explicit actor (overrides auth user).
     */
    public function security(
        string $event,
        ?Model $subject = null,
        array $meta = [],
        ?User $user = null,
    ): AuditLog {
        $audit = $this->persist(
            action: $event,
            subject: $subject,
            meta: $meta,
            user: $user,
            isSecurity: true,
        );

        $context = [
            'user_id'      => $audit->user_id,
            'ip'           => $audit->client_ip,
            'user_agent'   => $audit->user_agent,
            'subject_type' => $audit->subject_type,
            'subject_id'   => $audit->subject_id,
            'audit_log_id' => $audit->id,
            'meta'         => $meta,
        ];

        try {
            SecurityEventLogged::dispatch($event, $context, $audit);
        } catch (Throwable $e) {
            Log::warning('AuditLogger::security event dispatch failed', [
                'event'        => $event,
                'audit_log_id' => $audit->id,
                'error'        => $e->getMessage(),
            ]);
        }

        return $audit;
    }

    /**
     * Shared write path for {@see self::log()} and {@see self::security()}.
     *
     * AuditLog uses `$guarded = ['*']` (mass-assignment audit, 2026-05-13)
     * so we go through `forceCreate` — the canonical write path for
     * system-trusted rows.
     */
    private function persist(
        string $action,
        ?Model $subject,
        array $meta,
        ?User $user,
        bool $isSecurity,
    ): AuditLog {
        $user = $user ?? Auth::user();

        $request = app()->bound('request') ? request() : null;

        $attrs = [
            'user_id'      => $user?->getKey(),
            'action'       => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id'   => $subject?->getKey(),
            'client_ip'    => $request?->ip(),
            'user_agent'   => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'meta'         => $meta !== [] ? $meta : null,
        ];

        // Only set is_security when the column actually exists so this
        // service ships safely against environments where the migration
        // hasn't been run yet (rolling deploys / old test fixtures).
        if ($isSecurity && $this->columnExists()) {
            $attrs['is_security'] = true;
        }

        return AuditLog::forceCreate($attrs);
    }

    /**
     * Cached `audit_logs.is_security` column existence check. Runs at most
     * one `SHOW COLUMNS` query per process lifecycle.
     */
    private function columnExists(): bool
    {
        if (self::$hasIsSecurityColumn === null) {
            try {
                self::$hasIsSecurityColumn = Schema::hasColumn('audit_logs', 'is_security');
            } catch (Throwable) {
                // If the schema check itself fails (DB unavailable in a
                // unit test), assume the column is absent so we don't try
                // to write a non-existent attribute.
                self::$hasIsSecurityColumn = false;
            }
        }

        return self::$hasIsSecurityColumn;
    }

    /**
     * Test seam — clears the memoised column-existence flag so suites that
     * run migrations between test cases can re-detect the column.
     */
    public static function resetSchemaCache(): void
    {
        self::$hasIsSecurityColumn = null;
    }
}
