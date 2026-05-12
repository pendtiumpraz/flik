<?php

namespace App\Http\Controllers;

use App\Models\Coin;
use App\Models\Movie;
use App\Models\MovieQuizQuestion;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Ai\Tasks\QuizQuestionGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Movie Trivia Quiz Game.
 *
 * Flow:
 *   GET  /movie/{movie}/quiz             — start: lazy-generates questions, shows quiz UI
 *   POST /movie/{movie}/quiz             — submit: score + XP + coins + persist QuizAttempt
 *   GET  /movie/{movie}/quiz/leaderboard — top scorers (best score per user, time tiebreak)
 *
 * Gamification: 10 XP per correct answer + 5 coins per correct answer + a
 * 50 XP / 25 coin bonus for a perfect run.
 */
class QuizController extends Controller
{
    /** Default number of questions to generate when missing. */
    protected const DEFAULT_QUESTION_COUNT = 10;

    /** XP & coin rewards per correct answer. */
    protected const XP_PER_CORRECT   = 10;
    protected const COINS_PER_CORRECT = 5;

    /** Bonus for a perfect attempt. */
    protected const PERFECT_BONUS_XP    = 50;
    protected const PERFECT_BONUS_COINS = 25;

    public function __construct(protected QuizQuestionGenerator $generator) {}

    /**
     * Render the quiz play screen. Questions are generated on-the-fly if the
     * movie has none yet (e.g. first user to open the quiz for this film).
     */
    public function start(Movie $movie): View|RedirectResponse
    {
        $questions = MovieQuizQuestion::where('movie_id', $movie->id)
            ->orderBy('id')
            ->get();

        if ($questions->isEmpty()) {
            try {
                $questions = $this->generator->generate($movie, self::DEFAULT_QUESTION_COUNT);
            } catch (\Throwable $e) {
                Log::warning('QuizController: generation failed', [
                    'movie_id' => $movie->id,
                    'error'    => $e->getMessage(),
                ]);
                $questions = collect();
            }
        }

        if ($questions->isEmpty()) {
            return redirect()
                ->route('movies.show', $movie)
                ->with('error', 'Belum bisa membuat quiz untuk film ini. Coba lagi nanti.');
        }

        // Strip correct_option / explanation before sending to the view.
        // We never want the answers in HTML — they're checked on submit.
        $payload = $questions->map(fn (MovieQuizQuestion $q) => [
            'id'         => $q->id,
            'question'   => $q->question,
            'options'    => [
                'a' => $q->option_a,
                'b' => $q->option_b,
                'c' => $q->option_c,
                'd' => $q->option_d,
            ],
            'difficulty' => $q->difficulty,
        ])->values();

        return view('quiz.play', [
            'movie'     => $movie,
            'questions' => $payload,
            'total'     => $payload->count(),
        ]);
    }

    /**
     * Score the submitted answers, award XP+coins, save the attempt, and
     * show the result page.
     */
    public function submit(Request $request, Movie $movie): View|RedirectResponse
    {
        $validated = $request->validate([
            'answers'      => ['required', 'array'],
            'answers.*'    => ['nullable', 'string', 'in:a,b,c,d'],
            'time_seconds' => ['nullable', 'integer', 'min:0', 'max:7200'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $questions = MovieQuizQuestion::where('movie_id', $movie->id)
            ->orderBy('id')
            ->get();

        if ($questions->isEmpty()) {
            return redirect()
                ->route('quiz.start', $movie)
                ->with('error', 'Soal quiz hilang. Coba mulai ulang.');
        }

        $total       = $questions->count();
        $correct     = 0;
        $results     = [];           // per-question breakdown for the result view
        $answersMap  = $validated['answers'] ?? [];

        foreach ($questions as $q) {
            $given      = isset($answersMap[$q->id]) ? strtolower((string) $answersMap[$q->id]) : null;
            $isCorrect  = $given !== null && $q->isCorrect($given);

            if ($isCorrect) {
                $correct++;
            }

            $results[] = [
                'question'       => $q->question,
                'options'        => $q->options(),
                'given'          => $given,
                'correct_option' => $q->correct_option,
                'is_correct'     => $isCorrect,
                'explanation'    => $q->explanation,
            ];
        }

        $score        = $total > 0 ? (int) round(($correct / $total) * 100) : 0;
        $timeSeconds  = (int) ($validated['time_seconds'] ?? 0);
        $isPerfect    = $correct === $total && $total > 0;

        // Compute rewards.
        $xpGained    = $correct * self::XP_PER_CORRECT;
        $coinsGained = $correct * self::COINS_PER_CORRECT;
        if ($isPerfect) {
            $xpGained    += self::PERFECT_BONUS_XP;
            $coinsGained += self::PERFECT_BONUS_COINS;
        }

        // Persist attempt + grant rewards in one transaction.
        $attempt = DB::transaction(function () use ($user, $movie, $score, $total, $correct, $timeSeconds, $xpGained, $coinsGained, $isPerfect) {
            $attempt = QuizAttempt::create([
                'user_id'         => $user->id,
                'movie_id'        => $movie->id,
                'score'           => $score,
                'total_questions' => $total,
                'correct_count'   => $correct,
                'time_seconds'    => $timeSeconds,
                'completed_at'    => now(),
            ]);

            if ($xpGained > 0) {
                $user->getOrCreateLevel()->addXp($xpGained);
            }

            if ($coinsGained > 0) {
                $desc = $isPerfect
                    ? "Quiz {$movie->title}: perfect score +{$coinsGained}"
                    : "Quiz {$movie->title}: {$correct}/{$total} benar";
                Coin::earn($user->id, $coinsGained, 'quiz_reward', $desc);
            }

            return $attempt;
        });

        // Determine rank on the leaderboard (best score per user).
        $rank = $this->computeRank($movie->id, $user->id);

        return view('quiz.result', [
            'movie'        => $movie,
            'attempt'      => $attempt,
            'results'      => $results,
            'correct'      => $correct,
            'total'        => $total,
            'score'        => $score,
            'xpGained'     => $xpGained,
            'coinsGained'  => $coinsGained,
            'isPerfect'    => $isPerfect,
            'rank'         => $rank,
            'leaderboard'  => $this->topLeaderboard($movie->id, 10),
        ]);
    }

    /**
     * Standalone leaderboard page per movie.
     */
    public function leaderboard(Movie $movie): View
    {
        return view('quiz.leaderboard', [
            'movie'       => $movie,
            'leaderboard' => $this->topLeaderboard($movie->id, 50),
            'myRank'      => auth()->check() ? $this->computeRank($movie->id, auth()->id()) : null,
        ]);
    }

    /**
     * Best score per user for a movie, ordered by score desc, time asc, oldest first.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function topLeaderboard(int $movieId, int $limit = 10): \Illuminate\Support\Collection
    {
        // Best score per user, with the matching best time on that best score.
        return DB::table('quiz_attempts as qa')
            ->join('users as u', 'u.id', '=', 'qa.user_id')
            ->where('qa.movie_id', $movieId)
            ->selectRaw('u.id as user_id, u.name, MAX(qa.score) as best_score, MIN(qa.time_seconds) as best_time, MAX(qa.completed_at) as last_played')
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('best_score')
            ->orderBy('best_time')
            ->limit($limit)
            ->get();
    }

    /**
     * Compute the user's leaderboard rank (1-indexed). NULL if the user has
     * no attempts yet.
     */
    protected function computeRank(int $movieId, int $userId): ?int
    {
        $myBest = (int) QuizAttempt::where('movie_id', $movieId)
            ->where('user_id', $userId)
            ->max('score');

        if ($myBest === 0 && !QuizAttempt::where('movie_id', $movieId)->where('user_id', $userId)->exists()) {
            return null;
        }

        // Number of distinct users with a strictly higher best score on this movie.
        $aboveMe = DB::table('quiz_attempts')
            ->where('movie_id', $movieId)
            ->where('user_id', '!=', $userId)
            ->groupBy('user_id')
            ->havingRaw('MAX(score) > ?', [$myBest])
            ->get()
            ->count();

        return $aboveMe + 1;
    }
}
