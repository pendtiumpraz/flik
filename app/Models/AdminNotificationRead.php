<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AdminNotificationRead
 * --------------------------------------------------------------------------
 * Pivot row recording that {@see User} `user_id` has acknowledged
 * {@see AdminNotification} `admin_notification_id` at `read_at`.
 *
 * Distinct model (rather than a bare pivot) so we can ship one-shot
 * inserts via `AdminNotificationRead::updateOrCreate(...)` without
 * routing through the parent model's `users()` BelongsToMany attach
 * machinery (which always touches its `touches` cascade).
 */
class AdminNotificationRead extends Model
{
    protected $table = 'admin_notification_reads';

    /**
     * Mass assignment is safe — both FKs are integer IDs the service
     * controls; there is no privilege field on this table.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * We manage `read_at` explicitly; no created/updated stamps required.
     */
    public $timestamps = false;

    protected $casts = [
        'read_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<AdminNotification, AdminNotificationRead>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(AdminNotification::class, 'admin_notification_id');
    }

    /**
     * @return BelongsTo<User, AdminNotificationRead>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
