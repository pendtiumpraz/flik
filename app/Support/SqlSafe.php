<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helpers for safely composing SQL fragments that must not be parameterised.
 *
 * Column names, sort directions, and table identifiers cannot be passed as
 * PDO bindings — they are part of the query plan, not data — so any time
 * such a value originates from user input it MUST be checked against an
 * allowlist before being interpolated. This class centralises the pattern
 * so it's hard to get wrong.
 *
 * Example:
 *
 *   $sortable = ['created_at', 'updated_at', 'title', 'popularity'];
 *   $column   = SqlSafe::columnAllowlist($sortable, $request->input('sort'), 'created_at');
 *   $dir      = SqlSafe::direction($request->input('dir'));
 *
 *   Movie::query()->orderBy($column, $dir)->paginate();
 *
 * @see docs/security/sql-injection-audit.md
 */
final class SqlSafe
{
    /**
     * Strict column-name allowlist.
     *
     * Returns $candidate when (and only when) it appears in $allowed.
     * Otherwise returns $default. Both comparisons are strict (===)
     * to defeat type-juggling tricks (e.g. numeric-string vs int).
     *
     * @param  list<string>  $allowed   Whitelisted column / identifier names.
     * @param  mixed         $candidate Untrusted value (request input, etc).
     * @param  string        $default   Safe fallback when $candidate is not allowed.
     */
    public static function columnAllowlist(array $allowed, mixed $candidate, string $default): string
    {
        if (! is_string($candidate) || $candidate === '') {
            return $default;
        }

        // Defence in depth: an "allowed" column must still match the
        // narrow identifier shape we permit. Anything outside [A-Za-z0-9_.]
        // (with optional `table.column` form) is rejected even if some
        // future caller sneaks a comma-list into the allowlist by mistake.
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $candidate)) {
            return $default;
        }

        return in_array($candidate, $allowed, true) ? $candidate : $default;
    }

    /**
     * Normalise an untrusted sort direction to 'asc' or 'desc'.
     *
     * @param  mixed  $candidate Untrusted value (e.g. $request->input('dir')).
     * @param  'asc'|'desc'  $default
     * @return 'asc'|'desc'
     */
    public static function direction(mixed $candidate, string $default = 'asc'): string
    {
        if (is_string($candidate)) {
            $lower = strtolower(trim($candidate));
            if ($lower === 'asc' || $lower === 'desc') {
                return $lower;
            }
        }

        return $default === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Validate a bare table or column identifier (no dotted form).
     *
     * Returns the identifier when it matches the strict shape, else null.
     * Useful when the caller already has its own allowlist semantics but
     * still wants the regex guard.
     */
    public static function identifier(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $candidate) === 1 ? $candidate : null;
    }
}
