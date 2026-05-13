<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiUsageLog extends Model
{
    use HasFactory;

    /**
     * SECURITY: ai_usage_logs is a system-only sink written exclusively by
     * App\Services\Ai\UsageTracker after every AiClient::chat() call. End
     * users never write here. Guarding everything closes off accidental
     * mass-assignment routes (e.g. an admin endpoint bulk-import bug).
     *
     * @var array<int, string>
     */
    protected $guarded = ['*'];

    protected $casts = [
        'input_tokens'  => 'integer',
        'output_tokens' => 'integer',
        'cost_usd'      => 'decimal:6',
        'latency_ms'    => 'integer',
        'cache_hit'     => 'boolean',
        'success'       => 'boolean',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTotalTokensAttribute(): int
    {
        return (int) ($this->input_tokens + $this->output_tokens);
    }
}
