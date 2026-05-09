<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiProvider extends Model
{
    use HasFactory;

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
            'models' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo', 'o1-preview', 'o1-mini'],
        ],
        'anthropic' => [
            'label' => 'Anthropic Claude',
            'base_url' => 'https://api.anthropic.com/v1',
            'models' => ['claude-opus-4-7', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001'],
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'base_url' => 'https://api.deepseek.com/v1',
            'models' => ['deepseek-chat', 'deepseek-reasoner'],
        ],
        'google' => [
            'label' => 'Google Gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'models' => ['gemini-2.0-flash-exp', 'gemini-1.5-pro', 'gemini-1.5-flash'],
        ],
        'groq' => [
            'label' => 'Groq',
            'base_url' => 'https://api.groq.com/openai/v1',
            'models' => ['llama-3.3-70b-versatile', 'mixtral-8x7b-32768', 'llama-3.1-8b-instant'],
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
        $key = $this->api_key;
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
