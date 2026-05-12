<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI-generated multiple-choice trivia question.
 *
 * @property int    $id
 * @property int    $movie_id
 * @property string $question
 * @property string $option_a
 * @property string $option_b
 * @property string $option_c
 * @property string $option_d
 * @property string $correct_option   one of 'a'|'b'|'c'|'d'
 * @property ?string $explanation
 * @property string $difficulty        'easy'|'medium'|'hard'
 * @property ?\Illuminate\Support\Carbon $generated_at
 */
class MovieQuizQuestion extends Model
{
    use HasFactory;

    public const DIFFICULTY_EASY   = 'easy';
    public const DIFFICULTY_MEDIUM = 'medium';
    public const DIFFICULTY_HARD   = 'hard';

    public const DIFFICULTIES = [
        self::DIFFICULTY_EASY,
        self::DIFFICULTY_MEDIUM,
        self::DIFFICULTY_HARD,
    ];

    public const OPTIONS = ['a', 'b', 'c', 'd'];

    protected $fillable = [
        'movie_id',
        'question',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'correct_option',
        'explanation',
        'difficulty',
        'generated_at',
    ];

    protected $casts = [
        'movie_id'     => 'integer',
        'generated_at' => 'datetime',
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Get the four options as an associative array keyed by letter.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'a' => (string) $this->option_a,
            'b' => (string) $this->option_b,
            'c' => (string) $this->option_c,
            'd' => (string) $this->option_d,
        ];
    }

    /**
     * Lookup the text of the correct option.
     */
    public function correctAnswerText(): string
    {
        return $this->options()[$this->correct_option] ?? '';
    }

    public function isCorrect(string $answer): bool
    {
        return strtolower(trim($answer)) === strtolower((string) $this->correct_option);
    }
}
