<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks one ffmpeg/packaging job lifecycle for a movie's master file.
 *
 * Multiple rows per movie are expected (re-encodes, format additions). The
 * latest `completed` row determines what `movies.encoding_renditions` reflects.
 *
 * Lifecycle:
 *   queued → transcoding → encrypting → uploading → completed
 *                                                 ↘ failed
 *
 * Persistence is intentionally chatty (frequent progress writes) so the
 * admin UI can poll without guessing which stage we're in.
 */
class EncodingJob extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_TRANSCODING = 'transcoding';
    public const STATUS_ENCRYPTING = 'encrypting';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'movie_id',
        'status',
        'rendition_specs',
        'output_paths',
        'error_message',
        'progress_percent',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'rendition_specs' => 'array',
        'output_paths' => 'array',
        'progress_percent' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * The movie this encoding job belongs to.
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Mark the job as actively transcoding and stamp started_at.
     *
     * Idempotent: re-calling on an in-progress job just refreshes started_at
     * if it was somehow null, but won't reset progress.
     */
    public function markStarted(): void
    {
        $this->forceFill([
            'status' => self::STATUS_TRANSCODING,
            'started_at' => $this->started_at ?? now(),
            'error_message' => null,
        ])->save();
    }

    /**
     * Mark the job as completed and persist final output paths.
     *
     * @param  array<int|string, mixed>  $outputPaths  Per-rendition paths/manifests
     */
    public function markCompleted(array $outputPaths): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'output_paths' => $outputPaths,
            'progress_percent' => 100,
            'completed_at' => now(),
            'error_message' => null,
        ])->save();
    }

    /**
     * Mark the job as failed with a human-readable reason.
     *
     * Truncates over-long messages so we don't blow up TEXT column limits
     * when ffmpeg dumps a multi-page stderr.
     */
    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => mb_substr($reason, 0, 60_000),
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Update the progress percentage (clamped 0-100).
     *
     * Cheap to call repeatedly; uses an UPDATE on just the two fields rather
     * than re-saving the whole model to keep DB write amplification low when
     * ffmpeg streams progress lines.
     */
    public function updateProgress(int $percent): void
    {
        $clamped = max(0, min(100, $percent));

        $this->progress_percent = $clamped;

        // Avoid bumping updated_at on every progress tick (would defeat any
        // caching layered on the encoding row); use a targeted update.
        static::query()
            ->whereKey($this->getKey())
            ->update(['progress_percent' => $clamped]);
    }
}
