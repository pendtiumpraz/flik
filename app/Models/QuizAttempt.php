<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed quiz attempt by a user on a movie.
 *
 * @property int    $id
 * @property int    $user_id
 * @property int    $movie_id
 * @property int    $score              normalized 0–100
 * @property int    $total_questions
 * @property int    $correct_count
 * @property int    $time_seconds
 * @property ?\Illuminate\Support\Carbon $completed_at
 */
class QuizAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'movie_id',
        'score',
        'total_questions',
        'correct_count',
        'time_seconds',
        'completed_at',
    ];

    protected $casts = [
        'user_id'         => 'integer',
        'movie_id'        => 'integer',
        'score'           => 'integer',
        'total_questions' => 'integer',
        'correct_count'   => 'integer',
        'time_seconds'    => 'integer',
        'completed_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Best score per user per movie. Used by the leaderboard.
     */
    public function scopeBestPerUser($q, int $movieId)
    {
        return $q->where('movie_id', $movieId)
            ->select('user_id')
            ->selectRaw('MAX(score) as best_score')
            ->selectRaw('MIN(time_seconds) as best_time')
            ->groupBy('user_id');
    }
}
