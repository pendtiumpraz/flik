<?php

namespace App\Services\Ai\Tasks;

use App\Services\Ai\AiClient;

/**
 * AI-powered email subject-line A/B test generator.
 *
 * Generates N variants of an email subject line in Indonesian, each tagged with
 * a tone (curious / urgent / personal / playful) and a self-reported predicted
 * open-rate (0..1 float — informational, NOT a guarantee).
 *
 * Use cases:
 *   - 'win_back'                  — re-engage churned subscribers
 *   - 'new_release_announcement'  — flag a new title in the catalog
 *   - 'subscription_renewal'      — renewal reminders
 *   - 'free_trial_ending'         — trial-end nudges
 *   - 'recommendation'            — personalised "you might like" picks
 *   - 'promo_discount'            — discount/coupon campaigns
 *   - any other free-text intent — used verbatim in the prompt
 *
 * Output shape per variant:
 *   ['subject' => string (≤60 chars), 'tone' => string, 'predicted_open_rate' => float]
 */
class EmailSubjectAbTester
{
    public const MAX_SUBJECT_CHARS = 60;
    public const DEFAULT_VARIANTS  = 4;
    public const MAX_VARIANTS      = 10;

    /** Canonical tones the AI should rotate through. */
    public const TONES = ['curious', 'urgent', 'personal', 'playful'];

    /** Hint labels for known intents (free-text intents are passed through). */
    public const KNOWN_INTENTS = [
        'win_back'                 => 'Win-back email untuk subscriber yang lama tidak nonton',
        'new_release_announcement' => 'Pengumuman film baru di katalog',
        'subscription_renewal'     => 'Reminder perpanjangan langganan',
        'free_trial_ending'        => 'Reminder masa free-trial akan berakhir',
        'recommendation'           => 'Rekomendasi film personal berdasarkan riwayat tonton',
        'promo_discount'           => 'Penawaran promo/diskon langganan',
    ];

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Generate $variants subject-line variants for the given email intent.
     *
     * @param  string  $emailIntent  Either a known intent key or free text describing the email.
     * @param  array   $context      Optional metadata: ['user_name' => ?, 'movie_title' => ?, 'plan_name' => ?, 'discount_pct' => ?, ...]
     * @param  int     $variants     1..10 (default 4).
     * @return array<int, array{subject: string, tone: string, predicted_open_rate: float}>
     */
    public function generate(string $emailIntent, array $context = [], int $variants = self::DEFAULT_VARIANTS): array
    {
        $variants    = max(1, min(self::MAX_VARIANTS, $variants));
        $emailIntent = trim($emailIntent) !== '' ? $emailIntent : 'general_engagement';

        $response = $this->ai->chat(
            messages: [
                ['role' => 'system', 'content' => $this->buildSystemPrompt($emailIntent, $variants)],
                ['role' => 'user',   'content' => $this->buildUserPrompt($emailIntent, $context, $variants)],
            ],
            options: [
                'temperature' => 0.95,
                'max_tokens'  => 600,
            ],
        );

        return $this->parseAndClamp($response['content'] ?? '', $variants);
    }

    protected function buildSystemPrompt(string $intent, int $variants): string
    {
        $intentLabel = self::KNOWN_INTENTS[$intent] ?? $intent;
        $tonesCsv    = implode(' / ', self::TONES);

        return 'You\'re a senior email-marketing copywriter for Indonesian streaming platform FLiK. '
            . "Generate exactly {$variants} A/B test subject lines in Bahasa Indonesia for: {$intentLabel}. "
            . 'Setiap subject line WAJIB:'
            . ' (1) max ' . self::MAX_SUBJECT_CHARS . ' karakter (termasuk emoji),'
            . ' (2) HINDARI spam triggers ("GRATIS!!!", semua huruf KAPITAL, lebih dari 1 tanda seru),'
            . ' (3) variasi tone — distribusikan antara: ' . $tonesCsv . ','
            . ' (4) emoji opsional (max 1 per subject), gunakan jika natural,'
            . ' (5) personalisasi jika konteks user tersedia ({user_name}, {movie_title}, dst).'
            . ' Output STRICT JSON array only — no code fences, no commentary. '
            . 'Shape: [{"subject": "string", "tone": "curious|urgent|personal|playful", '
            . '"predicted_open_rate": 0.0-1.0}, ...]. '
            . 'predicted_open_rate adalah perkiraan kualitatif kamu sebagai copywriter (NOT a real metric).';
    }

    protected function buildUserPrompt(string $intent, array $context, int $variants): string
    {
        $intentLabel = self::KNOWN_INTENTS[$intent] ?? $intent;

        $lines = [
            "Buat {$variants} variasi subject line email untuk campaign:",
            '',
            'Intent  : ' . $intent . ($intent !== $intentLabel ? ' (' . $intentLabel . ')' : ''),
        ];

        // Inject context fields verbatim — caller is responsible for deciding what to share.
        if (!empty($context)) {
            $lines[] = '';
            $lines[] = 'Konteks user/campaign:';
            foreach ($context as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $lines[] = '- ' . $key . ': ' . (string) $value;
            }
        }

        $lines[] = '';
        $lines[] = 'Aturan output:';
        $lines[] = '- Bahasa Indonesia.';
        $lines[] = '- Max ' . self::MAX_SUBJECT_CHARS . ' karakter per subject.';
        $lines[] = '- Distribusikan tone: ' . implode(', ', self::TONES) . '.';
        $lines[] = '- Hindari spam triggers (huruf kapital semua, tanda seru berlebihan, "GRATIS!!").';
        $lines[] = '- HANYA balas JSON array valid. Tanpa code fence.';

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array{subject:string, tone:string, predicted_open_rate:float}>
     */
    protected function parseAndClamp(string $raw, int $variants): array
    {
        $items = $this->extractJsonArray($raw);

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $subject = trim((string) ($item['subject'] ?? ''));
            $tone    = trim((string) ($item['tone']    ?? ''));
            $rate    = $item['predicted_open_rate'] ?? null;

            if ($subject === '') continue;

            $out[] = [
                'subject'             => $this->clamp($subject, self::MAX_SUBJECT_CHARS),
                'tone'                => $this->normalizeTone($tone),
                'predicted_open_rate' => $this->normalizeRate($rate),
            ];

            if (count($out) >= $variants) break;
        }

        // Fallback if AI returned junk
        if (empty($out)) {
            $out[] = [
                'subject'             => 'FLiK punya rekomendasi spesial buat kamu',
                'tone'                => 'personal',
                'predicted_open_rate' => 0.18,
            ];
        }

        return $out;
    }

    protected function normalizeTone(string $tone): string
    {
        $tone = mb_strtolower(trim($tone));
        return in_array($tone, self::TONES, true) ? $tone : 'curious';
    }

    /** Coerce predicted_open_rate to a 0..1 float. Accepts strings like "0.32" or "32%". */
    protected function normalizeRate(mixed $rate): float
    {
        if (is_numeric($rate)) {
            $val = (float) $rate;
        } elseif (is_string($rate)) {
            $clean = trim(str_replace(['%', ','], ['', '.'], $rate));
            $val   = is_numeric($clean) ? (float) $clean : 0.20;
            // If it looked like a percent (>1) divide
            if ($val > 1) {
                $val /= 100.0;
            }
        } else {
            $val = 0.20;
        }
        return round(max(0.0, min(1.0, $val)), 3);
    }

    /** @return array<int, mixed> */
    protected function extractJsonArray(string $raw): array
    {
        $clean = trim($raw);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        $first = strpos($clean, '[');
        $last  = strrpos($clean, ']');
        if ($first !== false && $last !== false && $last > $first) {
            $clean = substr($clean, $first, $last - $first + 1);
        }

        $decoded = json_decode($clean, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Multibyte-safe hard clamp. */
    protected function clamp(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
}
