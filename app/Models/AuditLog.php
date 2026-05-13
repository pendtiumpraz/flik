<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    /**
     * SECURITY: audit_logs is a system-only sink. Only AuditLogger writes
     * here, and it builds the row from server-trusted values (auth user,
     * request IP/UA, structured action constants). Guarding everything
     * means even if someone wires AuditLog::create($input) by mistake,
     * the write becomes a no-op rather than a tamper vector.
     *
     * @var array<int, string>
     */
    protected $guarded = ['*'];

    protected $casts = [
        'meta' => 'array',
        'subject_id' => 'integer',
        'is_security' => 'boolean',
    ];

    // ── Relations ─────────────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ────────────────────────────────────────────────
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeActionPrefix(Builder $query, string $prefix): Builder
    {
        return $query->where('action', 'like', $prefix.'%');
    }

    public function scopeOnSubject(Builder $query, string $type, int $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
        return $query;
    }

    /**
     * "Security only" filter — used by /admin/audit-logs filter chip.
     *
     * Falls back to a string-prefix match when the dedicated `is_security`
     * column hasn't been migrated yet, so the scope is safe to query from
     * application code regardless of migration state.
     */
    public function scopeSecurityOnly(Builder $query): Builder
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('audit_logs', 'is_security')) {
            return $query->where('is_security', true);
        }

        return $query->where(function (Builder $q): void {
            $q->where('action', 'like', 'auth.%')
              ->orWhere('action', 'like', 'security.%')
              ->orWhere('action', 'like', 'admin.%')
              ->orWhere('action', 'like', 'privacy.%')
              ->orWhere('action', 'like', 'payment.chargeback')
              ->orWhere('action', 'like', 'drm.key%');
        });
    }

    // ── Helpers ───────────────────────────────────────────────
    /**
     * Best-effort resolution of the related subject model instance.
     * Returns null if class missing or row deleted.
     */
    public function resolveSubject(): ?Model
    {
        if (! $this->subject_type || ! $this->subject_id) {
            return null;
        }
        if (! class_exists($this->subject_type)) {
            return null;
        }

        /** @var class-string<Model> $class */
        $class = $this->subject_type;

        return $class::find($this->subject_id);
    }

    public function getActionGroupAttribute(): string
    {
        return str_contains($this->action, '.')
            ? explode('.', $this->action, 2)[0]
            : $this->action;
    }

    /**
     * Severity bucket for the row, derived from the action name via
     * {@see \App\Support\SecurityEvents::severity()}. Returns 'low' for
     * non-security rows so callers can render uniformly.
     *
     * @return 'low'|'medium'|'high'|'critical'
     */
    public function getSeverityAttribute(): string
    {
        if (! class_exists(\App\Support\SecurityEvents::class)) {
            return 'low';
        }
        return \App\Support\SecurityEvents::severity($this->action);
    }
}
