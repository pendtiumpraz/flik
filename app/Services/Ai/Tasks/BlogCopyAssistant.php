<?php

declare(strict_types=1);

namespace App\Services\Ai\Tasks;

use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;

/**
 * AI editorial assistant for the blog CMS.
 *
 *   - suggestTitles(topic)  → 5 alternative headline options
 *   - outlineFrom(brief)    → markdown outline (H2 sections + bullets)
 *   - enrich(draft)         → polished prose with Indonesian flavour and
 *                              suggested in-text movie mentions
 *
 * Every method fails soft. A provider outage / quota error / malformed
 * response returns the safest fallback (empty array, raw input echo)
 * instead of throwing — callers (admin AJAX endpoint) render the
 * fallback in the UI so a one-off API hiccup never blocks composing.
 */
class BlogCopyAssistant
{
    /**
     * Topical voice we want every AI-assisted post to inherit.
     * Mentioned once here so the three task methods share a single style.
     */
    private const VOICE = 'Suara editorial FLiK: percaya diri, sinefil, hangat tapi tidak lebay. '
        . 'Gunakan Bahasa Indonesia natural, sesekali boleh sisipkan istilah perfilman global '
        . 'kalau membantu maksud. Hindari clickbait dan superlatif kosong.';

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Generate 5 headline alternatives for `$topic`.
     *
     * @return array<int, string>
     */
    public function suggestTitles(string $topic): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            return [];
        }

        $system = self::VOICE . "\n"
            . 'Tugas: usulkan 5 judul artikel blog yang menarik untuk topik berikut. '
            . 'Output WAJIB strict JSON tanpa markdown fence dengan format: '
            . '{"titles":["...","...","...","...","..."]}. '
            . 'Setiap judul: 5-12 kata, judul kasus (capitalized), tanpa tanda kutip pembungkus.';

        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "Topik: {$topic}"],
                ],
                [
                    'max_tokens'  => 400,
                    'temperature' => 0.85,
                ],
                'blog.copy.titles',
            );

            $titles = $this->decodeStringList($response['content'] ?? '', 'titles');

            return array_slice($titles, 0, 5);
        } catch (\Throwable $e) {
            Log::warning('BlogCopyAssistant::suggestTitles failed', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Produce a markdown outline (H2 sections + 2-4 bullets each) from
     * the supplied brief. Returned text is plain markdown, ready to
     * paste into the post body field.
     */
    public function outlineFrom(string $brief): string
    {
        $brief = trim($brief);
        if ($brief === '') {
            return '';
        }

        $system = self::VOICE . "\n"
            . 'Tugas: buat outline artikel dalam format markdown. '
            . 'Struktur: '
            . '`## Pendahuluan` lalu 5-7 section H2 lain yang relevan, masing-masing dengan 2-4 bullet point. '
            . 'Output TEKS MARKDOWN MURNI saja — JANGAN bungkus dengan ``` atau JSON.';

        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "Brief: {$brief}"],
                ],
                [
                    'max_tokens'  => 700,
                    'temperature' => 0.6,
                ],
                'blog.copy.outline',
            );

            $md = (string) ($response['content'] ?? '');
            // Defensive: strip stray fences just in case.
            $md = preg_replace('/```(?:markdown|md)?\s*(.+?)\s*```/is', '$1', $md) ?? $md;

            return trim($md);
        } catch (\Throwable $e) {
            Log::warning('BlogCopyAssistant::outlineFrom failed', [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Polish a draft: tighten copy, add Indonesian flavour, and surface
     * suggested movie mentions as inline notes the editor can act on.
     *
     * Returns enriched markdown. On failure, falls back to the original
     * draft so the editor doesn't lose any work.
     */
    public function enrich(string $draft): string
    {
        $draft = trim($draft);
        if ($draft === '') {
            return '';
        }

        $system = self::VOICE . "\n"
            . 'Tugas: poles draft artikel berikut. Pertahankan struktur dan poin asli, '
            . 'tapi tingkatkan keterbacaan, tambahkan kekayaan istilah sinema Indonesia '
            . '(contoh: jagat sinema, ranah laga, mise-en-scène), dan jika relevan, '
            . 'tambahkan baris notasi `> Saran film: <Judul> (<tahun>)` setelah '
            . 'paragraf yang membahas tipe film tertentu. '
            . 'Output TEKS MARKDOWN MURNI saja — JANGAN bungkus dengan ``` atau JSON.';

        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $draft],
                ],
                [
                    'max_tokens'  => 1400,
                    'temperature' => 0.55,
                ],
                'blog.copy.enrich',
            );

            $md = (string) ($response['content'] ?? '');
            $md = preg_replace('/```(?:markdown|md)?\s*(.+?)\s*```/is', '$1', $md) ?? $md;
            $md = trim($md);

            // Empty / blank reply → return original so the editor keeps work.
            return $md !== '' ? $md : $draft;
        } catch (\Throwable $e) {
            Log::warning('BlogCopyAssistant::enrich failed', [
                'error' => $e->getMessage(),
            ]);

            return $draft;
        }
    }

    /**
     * Pull a string list out of a strict-JSON reply. Returns [] when the
     * response is unparseable so the caller can fall back gracefully.
     *
     * @return array<int, string>
     */
    private function decodeStringList(string $raw, string $key): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $raw, $m)) {
            $raw = $m[1];
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($decoded) || ! isset($decoded[$key]) || ! is_array($decoded[$key])) {
            return [];
        }

        $out = [];
        foreach ($decoded[$key] as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }
            $s = trim((string) $item, " \t\n\r\0\x0B\"'");
            if ($s !== '' && mb_strlen($s) <= 200) {
                $out[] = $s;
            }
        }

        return $out;
    }
}
