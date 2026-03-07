<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coin extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'amount', 'type', 'description'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function earn(int $userId, int $amount, string $type, string $description = null): self
    {
        return static::create([
            'user_id' => $userId,
            'amount' => abs($amount),
            'type' => $type,
            'description' => $description,
        ]);
    }

    public static function spend(int $userId, int $amount, string $type, string $description = null): self
    {
        return static::create([
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
