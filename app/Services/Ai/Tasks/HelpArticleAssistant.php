<?php

declare(strict_types=1);

namespace App\Services\Ai\Tasks;

use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;

/**
 * AI editorial assistant for the Help Center.
 *
 *   - suggestTitle($userQuestion)  → 3 candidate article titles for an
 *                                    unanswered support question.
 *   - draftAnswer($question)       → markdown draft answer with
 *                                    placeholder screenshots / steps.
 *   - improveArticle($existing)    → polish + correct + append common FAQs.
 *
 * Every method fails soft (returns empty array or original text on
 * provider error) so a one-off API outage never blocks a content
 * editor from composing manually.
 */
class HelpArticleAssistant
{
    /**
     * Editorial voice shared by every method so AI-assisted articles
     * inherit a consistent tone — friendly, plain Bahasa Indonesia,
     * step-driven, no jargon dump.
     */
    private const VOICE = 'Suara Pusat Bantuan FLiK: ramah, jelas, langsung ke poin. '
        . 'Gunakan Bahasa Indonesia natural tingkat SMA. Hindari jargon teknis '
        . 'tanpa penjelasan. Selalu pakai sudut pandang membantu pengguna '
        . '("Anda" atau bentuk netral). Hindari kalimat penjual.';

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Suggest 3 article titles for a user question.
     *
     * @return array<int, string>
     */
    public function suggestTitle(string $userQuestion): array
    {
        $userQuestion = trim($userQuestion);
        if ($userQuestion === '') {
            return [];
        }

        $system = self::VOICE . "\n"
            . 'Tugas: usulkan 3 judul artikel Pusat Bantuan yang cocok untuk '
            . 'pertanyaan pengguna berikut. Setiap judul: 5-12 kata, mulai '
            . 'dengan kata kerja atau "Cara..." / "Bagaimana...", judul kasus. '
            . 'Output WAJIB strict JSON tanpa markdown fence: '
            . '{"titles":["...","...","..."]}';

        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "Pertanyaan pengguna: {$userQuestion}"],
                ],
                [
                    'max_tokens'  => 300,
                    'temperature' => 0.85,
                ],
                'help.assistant.suggest_title',
            );

            return array_slice($this->decodeStringList($response['content'] ?? '', 'titles'), 0, 3);
        } catch (\Throwable $e) {
            Log::warning('HelpArticleAssistant::suggestTitle failed', [
                'question' => mb_substr($userQuestion, 0, 80),
                'error'    => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Produce a first-draft markdown answer to a support question.
     * Output uses placeholder lines so the editor can drop screenshots
     * in without retyping the structure.
     */
    public function draftAnswer(string $question): string
    {
        $question = trim($question);
        if ($question === '') {
            return '';
        }

        $system = self::VOICE . "\n"
            . 'Tugas: tulis draft jawaban artikel Pusat Bantuan untuk pertanyaan '
            . 'pengguna. Struktur:'
            . "\n- Paragraf pembuka singkat (1-2 kalimat) yang merangkum jawaban."
            . "\n- `## Langkah-langkah` dengan list bernomor 3-7 langkah."
            . "\n- Setiap langkah yang butuh visual diberi placeholder baris: `![Screenshot: deskripsi singkat](placeholder)`."
            . "\n- `## Catatan` opsional di akhir kalau ada kasus khusus."
            . "\nOutput TEKS MARKDOWN MURNI — JANGAN bungkus dengan ``` atau JSON.";

        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "Pertanyaan: {$question}"],
                ],
                [
                    'max_tokens'  => 900,
                    'temperature' => 0.5,
                ],
                'help.assistant.draft_answer',
            );

            $md = (string) ($response['content'] ?? '');
            $md = preg_replace('/```(?:markdown|md)?\s*(.+?)\s*```/is', '$1', $md) ?? $md;

            return trim($md);
        } catch (\Throwable $e) {
            Log::warning('HelpArticleAssistant::draftAnswer failed', [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Polish an existing article: tighten phrasing, correct typos, and
     * append a `## Pertanyaan Umum` section with 3-5 common follow-up FAQs.
     *
     * On failure returns the original draft so the editor doesn't lose work.
     */
    public function improveArticle(string $existing): string
    {
        $existing = trim($existing);
        if ($existing === '') {
            return '';
        }

        $system = self::VOICE . "\n"
            . 'Tugas: poles artikel Pusat Bantuan berikut. Pertahankan struktur dan '
            . 'maksud asli, tapi perbaiki keterbacaan, koreksi salah ketik atau '
            . 'kalimat kaku, dan TAMBAHKAN di akhir bagian baru: '
            . '`## Pertanyaan Umum` dengan 3-5 pasangan Q/A pendek dalam format markdown '
            . '(`**Q: ...**` lalu paragraf jawaban). '
            . 'Output TEKS MARKDOWN MURNI — JANGAN bungkus dengan ``` atau JSON.';

        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $existing],
                ],
                [
                    'max_tokens'  => 1500,
                    'temperature' => 0.5,
                ],
                'help.assistant.improve',
            );

            $md = (string) ($response['content'] ?? '');
            $md = preg_replace('/```(?:markdown|md)?\s*(.+?)\s*```/is', '$1', $md) ?? $md;
            $md = trim($md);

            return $md !== '' ? $md : $existing;
        } catch (\Throwable $e) {
            Log::warning('HelpArticleAssistant::improveArticle failed', [
                'error' => $e->getMessage(),
            ]);

            return $existing;
        }
    }

    /**
     * Tolerant JSON-list decoder, same shape as BlogCopyAssistant. Returns
     * an empty array when the response is unparseable so callers can fall
     * back gracefully.
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
        $end   = strrpos($raw, '}');
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
