<?php

namespace App\Services\Ai\Subtitle;

use App\Models\Movie;
use App\Models\MovieSubtitle;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Storage;

/**
 * Speaker tag identifier (L2).
 *
 * Adds best-effort `[CHARACTER NAME]: dialog` prefixes to every cue in a
 * subtitle by feeding the cue stream + cast list to an AI. The AI is told
 * to be conservative — leave dialog untagged where it cannot be sure.
 *
 * Saved as a new MovieSubtitle row with variant='speaker-tagged'.
 *
 * The cast list is built from `$movie->castMembers()` (cast_movie pivot's
 * `character` column when present; cast `name` otherwise).
 */
class SpeakerIdentifier
{
    /**
     * Cues per AI call. We send cues in larger windows than translation so
     * the model has enough surrounding context to resolve who-is-speaking.
     */
    protected const BATCH_SIZE = 40;

    public function __construct(
        protected AiClient $ai,
        protected WebVttHelper $vtt
    ) {}

    /**
     * Produce a speaker-tagged variant of a source subtitle for a movie.
     */
    public function addSpeakerTags(MovieSubtitle $source, Movie $movie): MovieSubtitle
    {
        if ($source->movie_id !== $movie->id) {
            throw new \InvalidArgumentException(
                "Source subtitle (movie #{$source->movie_id}) does not belong to movie #{$movie->id}."
            );
        }

        $sourceVtt = Storage::disk($source->disk)->get($source->webvtt_path);
        if (!$sourceVtt) {
            throw new \RuntimeException("Source subtitle file not found: {$source->webvtt_path}");
        }

        $cues = $this->vtt->parse($sourceVtt);
        if (empty($cues)) {
            throw new \RuntimeException("Source VTT has no cues.");
        }

        $castList = $this->buildCastList($movie);
        $langName = LanguageCatalog::name($source->language_code);

        $taggedTexts = $this->tagCues(
            collect($cues)->pluck('text')->toArray(),
            $castList,
            $langName
        );

        $taggedCues = $this->vtt->replaceTexts($cues, $taggedTexts);
        $taggedVtt = $this->vtt->build($taggedCues);

        $disk = config('filesystems.default', 'public');
        $variant = 'speaker-tagged';
        $filename = sprintf(
            'subtitles/%s/%s-%s.vtt',
            $movie->slug,
            $source->language_code,
            $variant
        );
        Storage::disk($disk)->put($filename, $taggedVtt);

        return MovieSubtitle::updateOrCreate(
            [
                'movie_id'      => $movie->id,
                'language_code' => $source->language_code,
                'variant'       => $variant,
            ],
            [
                'label'             => $langName . ' (Speaker tags)',
                'webvtt_path'       => $filename,
                'disk'              => $disk,
                'is_auto_generated' => false,
                'is_translated'     => false,
                'source_language'   => $source->language_code,
                'generator_model'   => 'deepseek-v4-flash',
                'status'            => 'ready',
                'cue_count'         => count($cues),
                'duration_seconds'  => $source->duration_seconds,
                'is_default'        => false,
                'is_active'         => true,
            ]
        );
    }

    /**
     * Build a comma-separated cast list ("CHARACTER (Actor), …") from the
     * movie's cast_movie pivot. Falls back to actor names alone when the
     * pivot has no `character` value.
     */
    protected function buildCastList(Movie $movie): string
    {
        $members = $movie->castMembers()->get();
        if ($members->isEmpty()) {
            return '(no cast list available — infer from dialog only)';
        }

        $parts = [];
        foreach ($members as $member) {
            $character = trim((string) ($member->pivot->character ?? ''));
            $name = trim((string) $member->name);

            if ($character !== '' && $name !== '') {
                $parts[] = "{$character} (played by {$name})";
            } elseif ($character !== '') {
                $parts[] = $character;
            } elseif ($name !== '') {
                $parts[] = $name;
            }
        }

        return $parts ? implode(', ', $parts) : '(no cast list available)';
    }

    /**
     * Send the cue stream through the AI in BATCH_SIZE windows.
     *
     * @param  array<int,string>  $texts
     * @return array<int,string>          Tagged texts in input order
     */
    protected function tagCues(array $texts, string $castList, string $langName): array
    {
        $batches = array_chunk($texts, self::BATCH_SIZE);
        $all = [];

        foreach ($batches as $batch) {
            $numbered = [];
            foreach ($batch as $i => $text) {
                $numbered[] = sprintf("[%d] %s", $i + 1, str_replace("\n", ' / ', $text));
            }
            $batchText = implode("\n", $numbered);

            $messages = [
                [
                    'role' => 'system',
                    'content' => "You are a film script analyst adding speaker name tags to a {$langName} subtitle file. " .
                        "For each cue, prepend the speaker's character name when you can identify them with reasonable confidence from context. " .
                        "Format: '[N] CHARACTER NAME: dialog'. " .
                        "If you are unsure who is speaking, output the cue WITHOUT a speaker prefix (still with the [N] prefix and original dialog text). " .
                        "Available characters for this film: {$castList}. " .
                        "Output ONLY the lines with their [N] prefix — no explanation, no markdown, no commentary.",
                ],
                [
                    'role' => 'user',
                    'content' => "Add speaker tags to these subtitle cues (keep [number] prefix, only tag where confident):\n\n{$batchText}",
                ],
            ];

            $result = $this->ai->chat($messages, [
                'max_tokens'  => 2000,
                'temperature' => 0.2,
            ]);

            $parsed = $this->parseNumberedResponse($result['content'], $batch);
            $all = array_merge($all, $parsed);
        }

        return $all;
    }

    /**
     * Parse [N]-prefixed AI output. Missing entries fall back to the original
     * cue text so we never drop dialog.
     *
     * @param  array<int,string>  $original
     * @return array<int,string>
     */
    protected function parseNumberedResponse(string $response, array $original): array
    {
        $count = count($original);
        $result = $original;
        $lines = preg_split('/\n+/', $response);

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\[(\d+)\]\s*(.+)$/u', $line, $m)) {
                $idx = (int) $m[1] - 1;
                if ($idx >= 0 && $idx < $count) {
                    $result[$idx] = str_replace(' / ', "\n", $m[2]);
                }
            }
        }

        return $result;
    }
}
