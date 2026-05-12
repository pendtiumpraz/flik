<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\MovieQuizQuestion;
use App\Services\Ai\AiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generate multiple-choice trivia questions for a movie.
 *
 * Idempotent: existing questions for the movie are wiped and replaced in one
 * DB transaction. Prompt is locked to strict-JSON output (no markdown fence),
 * graceful empty-collection return on parse / API failure.
 */
class QuizQuestionGenerator
{
    /** Hard guardrails so a misbehaving prompt can't blow up the DB. */
    protected const MIN_COUNT = 3;
    protected const MAX_COUNT = 20;

    /** Valid letters / difficulties mirror the migration enum. */
    protected const VALID_OPTIONS     = ['a', 'b', 'c', 'd'];
    protected const VALID_DIFFICULTY  = ['easy', 'medium', 'hard'];

    public function __construct(protected AiClient $ai) {}

    /**
     * Generate $count quiz questions for $movie.
     *
     * @return Collection<int, MovieQuizQuestion>
     */
    public function generate(Movie $movie, int $count = 10): Collection
    {
        $count = max(self::MIN_COUNT, min(self::MAX_COUNT, $count));

        $year  = $movie->release_date?->format('Y') ?: 'unknown';
        $title = trim((string) ($movie->original_title ?: $movie->title));

        $systemPrompt =
            "Anda adalah ahli trivia film. Buat {$count} pertanyaan pilihan ganda tentang film yang diberikan. "
            . 'Variasikan topik: alur cerita, pemeran, produksi, dan tema. '
            . 'Output WAJIB strict JSON array tanpa markdown fence, tanpa prosa pembuka, tanpa komentar. '
            . 'Schema setiap elemen: '
            . '{"question": string, "option_a": string, "option_b": string, "option_c": string, "option_d": string, '
            . '"correct_option": "a"|"b"|"c"|"d", "explanation": string, "difficulty": "easy"|"medium"|"hard"}. '
            . 'Pertanyaan, pilihan, dan penjelasan WAJIB dalam Bahasa Indonesia. '
            . 'Penjelasan ringkas 1 kalimat. Hindari pertanyaan yang ambigu — hanya satu opsi yang benar.';

        $userPrompt = sprintf(
            "Film:\n- Judul: %s\n- Judul asli: %s\n- Tahun: %s\n- Ringkasan: %s\n\n"
            . "Tugas: Hasilkan %d pertanyaan trivia pilihan ganda. Kembalikan HANYA JSON array.",
            $movie->title,
            $title,
            $year,
            mb_substr((string) $movie->overview, 0, 500),
            $count
        );

        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                [
                    'max_tokens'  => 2200,
                    'temperature' => 0.5,
                ],
                taskType: 'quiz.generate_questions',
                subject: $movie,
            );
        } catch (\Throwable $e) {
            Log::error('QuizQuestionGenerator: AI call failed', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);
            return collect();
        }

        $questions = $this->parseQuestions($response['content'] ?? '');

        if (empty($questions)) {
            Log::warning('QuizQuestionGenerator: no parseable questions', [
                'movie_id' => $movie->id,
                'raw'      => mb_substr((string) ($response['content'] ?? ''), 0, 400),
            ]);
            return collect();
        }

        $now  = now();
        $rows = [];
        foreach (array_slice($questions, 0, $count) as $q) {
            $rows[] = [
                'movie_id'       => $movie->id,
                'question'       => $q['question'],
                'option_a'       => $q['option_a'],
                'option_b'       => $q['option_b'],
                'option_c'       => $q['option_c'],
                'option_d'       => $q['option_d'],
                'correct_option' => $q['correct_option'],
                'explanation'    => $q['explanation'],
                'difficulty'     => $q['difficulty'],
                'generated_at'   => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        if (empty($rows)) {
            return collect();
        }

        DB::transaction(function () use ($movie, $rows) {
            MovieQuizQuestion::where('movie_id', $movie->id)->delete();
            MovieQuizQuestion::insert($rows);
        });

        return MovieQuizQuestion::where('movie_id', $movie->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Parse the AI response into a sanitized list of question dicts.
     *
     * @return array<int, array{
     *     question:string,
     *     option_a:string, option_b:string, option_c:string, option_d:string,
     *     correct_option:string, explanation:string, difficulty:string
     * }>
     */
    protected function parseQuestions(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        // Strip accidental markdown code fences.
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        // Snap to the outermost JSON array if the model surrounded it with prose.
        if (preg_match('/\[\s*\{.*\}\s*\]/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = isset($item['question']) ? trim((string) $item['question']) : '';
            $a        = isset($item['option_a']) ? trim((string) $item['option_a']) : '';
            $b        = isset($item['option_b']) ? trim((string) $item['option_b']) : '';
            $c        = isset($item['option_c']) ? trim((string) $item['option_c']) : '';
            $d        = isset($item['option_d']) ? trim((string) $item['option_d']) : '';
            $correct  = isset($item['correct_option']) ? strtolower(trim((string) $item['correct_option'])) : '';
            $explain  = isset($item['explanation']) ? trim((string) $item['explanation']) : '';
            $diff     = isset($item['difficulty']) ? strtolower(trim((string) $item['difficulty'])) : 'medium';

            // Drop malformed rows wholesale rather than persisting broken ones.
            if ($question === '' || $a === '' || $b === '' || $c === '' || $d === '') {
                continue;
            }
            if (!in_array($correct, self::VALID_OPTIONS, true)) {
                continue;
            }
            if (!in_array($diff, self::VALID_DIFFICULTY, true)) {
                $diff = 'medium';
            }

            $out[] = [
                'question'       => $question,
                'option_a'       => $a,
                'option_b'       => $b,
                'option_c'       => $c,
                'option_d'       => $d,
                'correct_option' => $correct,
                'explanation'    => $explain !== '' ? $explain : null,
                'difficulty'     => $diff,
            ];
        }

        return $out;
    }
}
