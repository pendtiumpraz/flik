<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\View\View;

/**
 * WAF admin: list and unban currently temp-banned IPs.
 *
 * The WAF middleware writes ban entries to cache key `waf:ip:ban:{ip}`
 * (TTL = config('security.waf.ip_ban_minutes')). Listing those keys
 * efficiently requires the cache driver to be Redis — that's the
 * production setup. For non-Redis drivers (array/file/database in
 * dev or test), we fall back to deriving the candidate IP set from
 * recent `security.waf.ip_banned` audit_log rows and probe each one
 * with Cache::has().
 *
 * Unban writes a `security.waf.ip_unbanned` audit row so the action is
 * traceable. The cache forget is best-effort — if the key already
 * expired, the audit row still gets written.
 */
final class WafController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /admin/security/waf-banned-ips
     */
    public function index(): View
    {
        $banned = $this->collectBannedIps();

        $recentBlocks = AuditLog::query()
            ->where('action', 'security.waf.blocked')
            ->latest('created_at')
            ->limit(50)
            ->get(['id', 'created_at', 'client_ip', 'user_agent', 'meta']);

        $recentBans = AuditLog::query()
            ->where('action', 'security.waf.ip_banned')
            ->latest('created_at')
            ->limit(20)
            ->get(['id', 'created_at', 'client_ip', 'meta']);

        return view('admin.security.waf', [
            'banned' => $banned,
            'recentBlocks' => $recentBlocks,
            'recentBans' => $recentBans,
            'mode' => (string) config('security.waf.mode', 'block'),
            'enabled' => (bool) config('security.waf.enabled', true),
            'threshold' => (int) config('security.waf.ip_ban_threshold', 5),
            'banMinutes' => (int) config('security.waf.ip_ban_minutes', 60),
        ]);
    }

    /**
     * POST /admin/security/waf-banned-ips/unban
     *
     * Validated via the {ip} route binding when present, else via the
     * `ip` form input. Both shapes are supported so the form can use
     * either an inline button per row or a generic "unban this IP"
     * field.
     */
    public function unban(Request $request, ?string $ip = null): RedirectResponse
    {
        $ip = $ip ?? (string) $request->input('ip', '');
        $ip = trim($ip);

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return back()->with('error', 'Invalid IP address.');
        }

        $banKey = 'waf:ip:ban:'.$ip;
        $hitsKey = 'waf:ip:hits:'.$ip;
        $existed = Cache::has($banKey);

        Cache::forget($banKey);
        // Also clear the rolling hit counter so the same IP doesn't
        // re-ban itself on the next single hit.
        Cache::forget($hitsKey);

        try {
            $this->audit->security('security.waf.ip_unbanned', [
                'ip' => $ip,
                'was_active' => $existed,
                'unbanned_by' => $request->user()?->id,
            ]);
        } catch (\Throwable) {
            // Audit failures must not surface as a UI error — the unban
            // already happened.
        }

        return back()->with(
            'success',
            $existed
                ? "IP {$ip} unbanned."
                : "IP {$ip} was no longer banned (entry already expired)."
        );
    }

    // ─────────────────────────────────────────────────────────────────
    //  Internals
    // ─────────────────────────────────────────────────────────────────

    /**
     * Returns currently-active ban entries: each as
     *   ['ip' => string, 'expires_in' => int|null, 'banned_at' => ?string]
     *
     * @return list<array{ip:string,expires_in:?int,banned_at:?string}>
     */
    private function collectBannedIps(): array
    {
        $candidates = $this->candidateIps();

        $rows = [];
        foreach ($candidates as $ip) {
            if (! Cache::has('waf:ip:ban:'.$ip)) {
                continue;
            }
            $rows[] = [
                'ip' => $ip,
                'expires_in' => $this->ttlSeconds($ip),
                'banned_at' => $this->lastBannedAt($ip),
            ];
        }

        // Newest first — banned_at desc, with nulls last.
        usort($rows, function (array $a, array $b): int {
            return strcmp((string) ($b['banned_at'] ?? ''), (string) ($a['banned_at'] ?? ''));
        });

        return $rows;
    }

    /**
     * Best-effort enumeration of IPs that *might* still be banned.
     *
     * - Redis driver: SCAN for `waf:ip:ban:*` keys (efficient, accurate).
     * - Other drivers: pull the last 200 `security.waf.ip_banned` audit
     *   rows. This will include some IPs whose ban already expired,
     *   which is fine — Cache::has() filters them out in the caller.
     *
     * @return list<string>
     */
    private function candidateIps(): array
    {
        if ((string) config('cache.default') === 'redis') {
            try {
                /** @var \Illuminate\Redis\Connections\Connection $conn */
                $conn = Redis::connection(config('cache.stores.redis.connection') ?? 'default');
                $prefix = (string) config('cache.prefix', '');
                $cachePrefix = $prefix !== '' ? $prefix.':' : '';
                $pattern = $cachePrefix.'waf:ip:ban:*';

                $cursor = '0';
                $found = [];
                do {
                    [$cursor, $keys] = $conn->scan($cursor, ['match' => $pattern, 'count' => 200]);
                    foreach ((array) $keys as $key) {
                        // Strip cache prefix + 'waf:ip:ban:' to recover the IP.
                        $key = (string) $key;
                        $stripped = $cachePrefix !== '' && str_starts_with($key, $cachePrefix)
                            ? substr($key, strlen($cachePrefix))
                            : $key;
                        if (str_starts_with($stripped, 'waf:ip:ban:')) {
                            $found[] = substr($stripped, strlen('waf:ip:ban:'));
                        }
                    }
                } while ($cursor !== '0' && $cursor !== 0);

                return array_values(array_unique($found));
            } catch (\Throwable) {
                // Fall through to the audit-log derived list.
            }
        }

        // Pull recent ban events; default cache driver doesn't expose
        // a key-scan API.
        $banMinutes = max(1, (int) config('security.waf.ip_ban_minutes', 60));
        $since = now()->subMinutes($banMinutes);

        return AuditLog::query()
            ->where('action', 'security.waf.ip_banned')
            ->where('created_at', '>=', $since)
            ->pluck('client_ip')
            ->filter(fn ($ip) => is_string($ip) && $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Returns remaining TTL seconds for the ban key, or null when the
     * underlying cache driver doesn't expose TTL.
     */
    private function ttlSeconds(string $ip): ?int
    {
        if ((string) config('cache.default') !== 'redis') {
            return null;
        }
        try {
            /** @var \Illuminate\Redis\Connections\Connection $conn */
            $conn = Redis::connection(config('cache.stores.redis.connection') ?? 'default');
            $prefix = (string) config('cache.prefix', '');
            $cachePrefix = $prefix !== '' ? $prefix.':' : '';
            $ttl = (int) $conn->ttl($cachePrefix.'waf:ip:ban:'.$ip);

            return $ttl > 0 ? $ttl : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function lastBannedAt(string $ip): ?string
    {
        $row = AuditLog::query()
            ->where('action', 'security.waf.ip_banned')
            ->where('client_ip', $ip)
            ->latest('created_at')
            ->first(['created_at']);

        return $row?->created_at?->toIso8601String();
    }
}
