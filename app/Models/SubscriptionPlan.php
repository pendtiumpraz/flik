<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'price', 'billing_cycle', 'max_screens',
        'video_quality', 'ads_free', 'download_enabled', 'features',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'ads_free' => 'boolean',
        'download_enabled' => 'boolean',
        'is_active' => 'boolean',
        'features' => 'array',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function getFormattedPriceAttribute(): string
    {
        if ($this->price == 0) return 'Gratis';
        return 'Rp ' . number_format($this->price, 0, ',', '.');
    }
}
