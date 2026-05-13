<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    /**
     * SECURITY: This table is *system-controlled* via PaymentController +
     * the Midtrans webhook. End users never POST data that lands here
     * directly. To prevent a forged checkout payload from rewriting
     * `status`, `transaction_id`, `paid_at`, etc., we use $guarded with
     * an explicit denylist instead of $fillable. Internal callers
     * (PaymentController::checkout, ::activateFreePlan, ::webhook,
     * UserDataEraser) write through forceFill(...) / forceCreate(...).
     *
     * @var array<int, string>
     */
    protected $guarded = [
        'id',
        'status',
        'transaction_id',
        'order_id',
        'payment_method',
        'paid_at',
        'cancelled_at',
        'amount',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'paid_at' => 'datetime',
        // PII at rest — encrypted via Laravel's encrypter (AES-256-CBC).
        // Column is TEXT (see migration 2026_05_10_040100) because encrypted
        // output is ~3.5x the plaintext length and would not fit in a
        // VARCHAR(255).
        'billing_address' => 'encrypted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where(function ($q) {
            $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
        });
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && (!$this->ends_at || $this->ends_at->isFuture());
    }
}
