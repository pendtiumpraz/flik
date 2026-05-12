<?php

declare(strict_types=1);

namespace App\Services\Ai\Tasks;

use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;

/**
 * Turns a cohort retention matrix into a short narrative (~200 words, Indonesian).
 *
 * Caller passes the array produced by {@see \App\Services\Analytics\CohortAnalyzer}
 * (either weekly or monthly). On any failure (no provider, API error, etc.)
 * we log and return a graceful fallback string — never throw. This matches the
 * convention shared by every other task under App\Services\Ai\Tasks\*.
 */
class CohortInsightGenerator
{
    /** Hard cap on how many cohorts we forward to the AI to keep tokens sane. */
    protected const MAX_COHORTS = 12;

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $cohortData  output of CohortAnalyzer
     * @return string  Indonesian narrative, ~200 words. Empty input or AI failure
     *                 returns a deterministic Indonesian fallback paragraph.
     */
    public function generate(array $cohortData): string
    {
        if (empty($cohortData)) {
            return 'Tidak ada data cohort yang cukup untuk dianalisis. Tunggu hingga ada pengguna baru terdaftar.';
        }

        // Compact each cohort to just the numbers the model needs.
        $compact = [];
        foreach (array_slice($cohortData, 0, self::MAX_COHORTS) as $row) {
            $start = $row['cohort_week_start']
                ?? $row['cohort_month_start']
                ?? ($row['label'] ?? 'unknown');
            $retentionPcts = [];
            foreach (($row['retention'] ?? []) as $point) {
                $retentionPcts[] = [
                    'p' => $point['period'] ?? null,
                    'pct' => $point['pct'],   // may be null for unreached future periods
                ];
            }
            $compact[] = [
                'cohort_start' => $start,
                'label' => $row['label'] ?? $start,
                'signup_count' => (int) ($row['signup_count'] ?? 0),
                'retention' => $retentionPcts,
            ];
        }

        $system = 'Anda adalah analis pertumbuhan & retensi untuk FLiK, layanan streaming film Indonesia. '
            .'Anda akan menerima matriks retensi cohort (per minggu atau per bulan). '
            .'Tugas Anda: tulis ringkasan naratif singkat dalam Bahasa Indonesia, sekitar 200 kata, '
            .'untuk dibaca oleh tim produk. '
            .'Jangan gunakan markdown fence, jangan keluarkan JSON — keluarkan paragraf prosa saja.';

        $user = "Analisis matriks retensi cohort berikut. Jawab pertanyaan-pertanyaan ini:\n"
            ."1) Cohort mana yang paling baik? Yang paling buruk?\n"
            ."2) Pola apa yang muncul (misal: drop tajam di minggu 1, stabilisasi di minggu 4, dsb)?\n"
            ."3) Rekomendasi konkret untuk meningkatkan retensi.\n\n"
            ."Aturan:\n"
            .'- Gunakan angka konkret dari data (contoh: "Cohort 2026-05-04 turun dari 100% ke 32% di minggu 1").'."\n"
            ."- Sekitar 200 kata, bahasa Indonesia, natural & ringkas.\n"
            ."- Jangan ulangi daftar angka mentah — interpretasikan.\n"
            ."- Nilai pct = null berarti periode tersebut belum terjadi; abaikan.\n\n"
            ."Data cohort:\n```json\n"
            .json_encode($compact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ."\n```";

        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                options: ['max_tokens' => 600, 'temperature' => 0.6],
                taskType: 'analytics.cohort_insight',
            );

            $text = trim((string) ($response['content'] ?? ''));
            if ($text === '') {
                return 'Analisis AI tidak tersedia saat ini. Silakan coba lagi nanti.';
            }

            // Strip any accidental markdown fence the model may have added.
            $text = preg_replace('/^```[a-z]*\s*|\s*```$/im', '', $text) ?? $text;

            return trim($text);
        } catch (\Throwable $e) {
            Log::warning('CohortInsightGenerator: AI call failed', [
                'error' => $e->getMessage(),
            ]);

            return 'Analisis AI tidak tersedia saat ini ('.\Illuminate\Support\Str::limit($e->getMessage(), 120).').';
        }
    }
}
