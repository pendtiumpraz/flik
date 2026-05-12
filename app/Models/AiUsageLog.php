<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiUsageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_provider_id',
        'task_type',
        'subject_type',
        'subject_id',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'latency_ms',
        'cache_hit',
        'success',
        'error_message',
    ];

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
