<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coin extends Model
{
    use HasFactory;

    /**
     * SECURITY: this is a write-only ledger. End users never POST `amount`
     * or `type` directly — coin grants and spends ALWAYS flow through the
     * static earn() / spend() helpers below (or admin tools). Guarding
     * everything closes the door on a request body sneaking a fat amount
     * into a controller that reaches Coin::create($request->only(...)).
     *
     * @var array<int, string>
     */
    protected $guarded = ['*'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function earn(int $userId, int $amount, string $type, string $description = null): self
    {
        return static::forceCreate([
            'user_id' => $userId,
            'amount' => abs($amount),
            'type' => $type,
            'description' => $description,
        ]);
    }

    public static function spend(int $userId, int $amount, string $type, string $description = null): self
    {
        return static::forceCreate([
            'user_id' => $userId,
            'amount' => -abs($amount),
            'type' => $type,
            'description' => $description,
        ]);
    }

    public static function balanceFor(int $userId): int
    {
        return (int) static::where('user_id', $userId)->sum('amount');
    }
}
