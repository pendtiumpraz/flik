<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'client_ip',
        'user_agent',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'subject_id' => 'integer',
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
}
