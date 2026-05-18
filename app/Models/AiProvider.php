<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiProvider extends Model
{
    use HasFactory;

    /**
     * Mass-assignable for admin AI-settings CRUD only.
     *
     * SECURITY: this table holds API keys for paid LLM providers — only the
     * /admin/ai-settings routes (gated by `can:admin`) write here. The
     * counter columns (`last_used_at`, `total_tokens_used`, `total_cost_usd`)
     * are bookkeeping for UsageTracker and stay in $fillable because the
     * existing `$provider->update([...])` calls there already build the
     * payload from server-trusted state, not request input.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'provider',
        'model',
        'api_key',
        'base_url',
        'settings',
        'is_active',
        'is_default',
        'priority',
        'last_used_at',
        'total_tokens_used',
        'total_cost_usd',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'last_used_at' => 'datetime',
        'total_cost_usd' => 'decimal:4',
    ];

    protected $hidden = ['api_key'];

    public const PROVIDERS = [
        'openai' => [
            'label' => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'models' => ['gpt-5.5', 'gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-nano', 'gpt-5', 'gpt-5-mini', 'gpt-4o', 'gpt-4o-mini-transcribe', 'gpt-4o-transcribe', 'whisper-1'],
        ],
        'anthropic' => [
            'label' => 'Anthropic Claude',
            'base_url' => 'https://api.anthropic.com/v1',
            'models' => ['claude-opus-4-7', 'claude-sonnet-4-6', 'claude-haiku-4-5'],
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'base_url' => 'https://api.deepseek.com/v1',
            'models' => ['deepseek-v4-flash', 'deepseek-v4-pro', 'deepseek-chat', 'deepseek-reasoner'],
        ],
        'google' => [
            'label' => 'Google Gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'models' => ['gemini-3.0-flash', 'gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite'],
        ],
        'groq' => [
            'label' => 'Groq',
            'base_url' => 'https://api.groq.com/openai/v1',
            'models' => ['llama-4-maverick', 'llama-4-scout', 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant'],
        ],
        'mistral' => [
            'label' => 'Mistral AI',
            'base_url' => 'https://api.mistral.ai/v1',
            'models' => ['mistral-large-latest', 'mistral-small-latest', 'codestral-latest'],
        ],
        'openrouter' => [
            'label' => 'OpenRouter (Multi-provider)',
            'base_url' => 'https://openrouter.ai/api/v1',
            'models' => ['custom — see openrouter.ai/models'],
        ],
        'custom' => [
            'label' => 'Custom (OpenAI-compatible)',
            'base_url' => '',
            'models' => ['custom'],
        ],
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('priority');
    }

    public function getProviderLabelAttribute(): string
    {
        return self::PROVIDERS[$this->provider]['label'] ?? ucfirst($this->provider);
    }

    public function getMaskedApiKeyAttribute(): string
    {
        // Wrap decrypt in try/catch — if APP_KEY rotated or the row was
        // written under a different key, the encrypted cast throws and
        // 500s the admin page. Surface a recoverable marker instead so the
        // operator can edit/re-enter the key.
        try {
            $key = $this->api_key;
        } catch (\Throwable $e) {
            return '⚠ DECRYPT FAILED — re-enter key';
        }

        if (!$key || strlen($key) < 10) {
            return str_repeat('•', 8);
        }

        return substr($key, 0, 6) . str_repeat('•', 12) . substr($key, -4);
    }

    public static function default(): ?self
    {
        return self::where('is_active', true)->where('is_default', true)->first()
            ?? self::active()->first();
    }
}
