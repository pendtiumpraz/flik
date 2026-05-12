<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /**
     * Persist an audit log entry.
     *
     * - `$user` defaults to the currently authenticated user (if any).
     * - Request context (IP, user agent) is auto-filled when running inside
     *   an HTTP request; falls back to null in CLI/queue contexts.
     *
     * @param  string                                 $action  Namespaced verb, e.g. "movie.uploaded".
     * @param  \Illuminate\Database\Eloquent\Model|null $subject Affected entity, if any.
     * @param  array<string,mixed>                    $meta    Structured detail (before/after, amounts, etc.).
     * @param  \App\Models\User|null                  $user    Explicit actor (overrides auth user).
     */
    public function log(
        string $action,
        ?Model $subject = null,
        array $meta = [],
        ?User $user = null,
    ): AuditLog {
        $user = $user ?? Auth::user();

        $request = app()->bound('request') ? request() : null;

        return AuditLog::create([
            'user_id' => $user?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'client_ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'meta' => $meta !== [] ? $meta : null,
        ]);
    }
}
