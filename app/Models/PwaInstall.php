<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PwaInstall — single row per "Add to Home Screen" / install event from
 * resources/js/pwa-install.js. Append-only; queried by the admin dashboard
 * for the install-count widget.
 */
class PwaInstall extends Model
{
    protected $fillable = [
        'user_id',
        'device',
        'ua',
        'outcome',
        'ip_hash',
        'installed_at',
    ];

    protected $casts = [
        'installed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
