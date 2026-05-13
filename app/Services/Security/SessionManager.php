<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * Reads and mutates rows in the `sessions` table to power the
 * "Manage active sessions" UI under /profile/sessions.
 *
 * Requires SESSION_DRIVER=database — when running on the `file` or
 * `cookie` driver the table will be empty and listForUser() returns
 * an empty Collection (the view shows an empty state).
 *
 * User-Agent parsing is intentionally inline (a few regexes) — we'd
 * rather not pull in jenssegers/agent for this one screen.
 */
class SessionManager
{
    /**
     * Active sessions for $userId, newest activity first.
     *
     * Each row is shaped:
     *   [
     *     'id'            => string  (session id, primary key)
     *     'ip'            => string|null
     *     'user_agent'    => string|null
     *     'browser'       => string  ('Chrome' / 'Firefox' / ...)
     *     'os'            => string  ('Windows' / 'macOS' / ...)
     *     'device'        => string  ('Desktop' / 'Mobile' / 'Tablet')
     *     'last_activity' => \Illuminate\Support\Carbon
     *     'is_current'    => bool
     *   ]
     */
    public function listForUser(int $userId): Collection
    {
        $currentId = $this->currentId();

        return DB::table('sessions')
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(function ($row) use ($currentId): array {
                $ua = (string) ($row->user_agent ?? '');
                $parsed = $this->parseUserAgent($ua);

                return [
                    'id' => (string) $row->id,
                    'ip' => $row->ip_address,
                    'user_agent' => $ua,
                    'browser' => $parsed['browser'],
                    'os' => $parsed['os'],
                    'device' => $parsed['device'],
                    'last_activity' => \Illuminate\Support\Carbon::createFromTimestamp((int) $row->last_activity),
                    'is_current' => $currentId !== null && hash_equals($currentId, (string) $row->id),
                ];
            });
    }

    /**
     * True when $sessionId matches the request's session cookie.
     */
    public function current(string $sessionId): bool
    {
        $currentId = $this->currentId();

        return $currentId !== null && hash_equals($currentId, $sessionId);
    }

    /**
     * Delete a single session row, scoped to the owner.
     *
     * Returns true when exactly one row was deleted, false otherwise
     * (wrong owner, unknown id, or no DB driver).
     */
    public function revoke(string $sessionId, int $userId): bool
    {
        $deleted = DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', $userId)
            ->delete();

        return $deleted > 0;
    }

    /**
     * Bulk-delete every session for $userId except the current one.
     * Returns the number of rows removed.
     */
    public function revokeAllExceptCurrent(int $userId, string $currentSessionId): int
    {
        return DB::table('sessions')
            ->where('user_id', $userId)
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    /**
     * The current request's session id, or null if no session is active
     * (CLI, queue, missing cookie, etc.).
     */
    public function currentId(): ?string
    {
        try {
            $id = Session::getId();

            return is_string($id) && $id !== '' ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Crude UA → {browser, os, device} parser. Keeps the dependency
     * footprint small; precise enough for displaying an icon + label.
     *
     * @return array{browser:string,os:string,device:string}
     */
    public function parseUserAgent(string $ua): array
    {
        if ($ua === '') {
            return ['browser' => 'Unknown', 'os' => 'Unknown', 'device' => 'Desktop'];
        }

        // Browser — order matters (Edge masquerades as Chrome, Opera as Chrome,
        // Brave as Chrome, etc.). Check the more specific brands first.
        $browser = match (true) {
            (bool) preg_match('/Edg(e|A|iOS)?\//i', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/i', $ua) => 'Opera',
            (bool) preg_match('/Brave\//i', $ua) => 'Brave',
            (bool) preg_match('/Vivaldi\//i', $ua) => 'Vivaldi',
            (bool) preg_match('/Firefox\//i', $ua) => 'Firefox',
            (bool) preg_match('/CriOS\//', $ua) => 'Chrome',
            (bool) preg_match('/Chrome\//i', $ua) => 'Chrome',
            (bool) preg_match('/Safari\//i', $ua) => 'Safari',
            (bool) preg_match('/MSIE |Trident\//i', $ua) => 'Internet Explorer',
            default => 'Unknown',
        };

        // OS — iOS / Android first (they also contain "Mac OS X" / "Linux").
        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'iOS',
            (bool) preg_match('/Android/i', $ua) => 'Android',
            (bool) preg_match('/Windows NT 10/i', $ua) => 'Windows 10/11',
            (bool) preg_match('/Windows NT/i', $ua) => 'Windows',
            (bool) preg_match('/Mac OS X|Macintosh/i', $ua) => 'macOS',
            (bool) preg_match('/CrOS/i', $ua) => 'ChromeOS',
            (bool) preg_match('/Linux/i', $ua) => 'Linux',
            default => 'Unknown',
        };

        // Device class.
        $device = match (true) {
            (bool) preg_match('/iPad|Tablet/i', $ua) => 'Tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Android.*Mobile/i', $ua) => 'Mobile',
            default => 'Desktop',
        };

        return ['browser' => $browser, 'os' => $os, 'device' => $device];
    }
}
