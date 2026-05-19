<?php

declare(strict_types=1);

namespace App\Services\Ai\Tasks;

use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;

/**
 * CampaignCopyGenerator — produces a full draft (3 subject variants,
 * preheader, HTML body) for an admin-composed email campaign.
 *
 * Distinct from {@see EmailPersonalizer} which generates copy targeted at
 * a single user. This generator targets a SEGMENT — the AI sees the goal +
 * tone + audience description and emits campaign-level copy that uses
 * personalization TOKENS ({{first_name}}, {{plan_name}}) that the mailable
 * substitutes per-recipient at send time.
 *
 * @phpstan-type DraftShape array{
 *     subjects: list<string>,
 *     preheader: string,
 *     html_body: string,
 *     plain_body: string,
 * }
 */
class CampaignCopyGenerator
{
    public const TONES = ['warm', 'cinematic', 'urgent', 'playful', 'formal'];

    public const TOKEN_HINTS = [
        '{{first_name}}' => 'recipient first name',
        '{{plan_name}}'  => 'recipient active subscription plan name',
    ];

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Generate a draft. Returns the normalised draft shape on success.
     * On AI failure, returns a deterministic Indonesian-language fallback
     * so the composer UI always has SOMETHING to show.
     *
     * @return DraftShape
     */
    public function generate(string $goal, string $tone = 'warm', string $audience = ''): array
    {
        $goal = trim($goal);
        $tone = $this->normalizeTone($tone);
        $audience = trim($audience);

        if ($goal === '') {
            return $this->fallback('Email kampanye FLiK', $tone, $audience);
        }

        try {
            return $this->callAi($goal, $tone, $audience);
        } catch (\Throwable $e) {
            Log::warning('CampaignCopyGenerator: AI call failed', [
                'goal'  => mb_substr($goal, 0, 120),
                'tone'  => $tone,
                'error' => $e->getMessage(),
            ]);
            return $this->fallback($goal, $tone, $audience);
        }
    }

    // ── AI plumbing ───────────────────────────────────────────

    /**
     * @return DraftShape
     */
    private function callAi(string $goal, string $tone, string $audience): array
    {
        $systemPrompt = $this->systemPrompt();
        $userPrompt = $this->userPrompt($goal, $tone, $audience);

        $response = $this->ai->chat(
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            options: [
                'max_tokens'  => 1200,
                'temperature' => 0.85,
            ],
            taskType: 'email.campaign.draft',
        );

        $content = (string) ($response['content'] ?? '');
        $parsed = $this->parseStrictJson($content);

        if ($parsed === null) {
            // Schema violation — emit a fallback rather than blow up the UI.
            Log::warning('CampaignCopyGenerator: AI response failed schema check', [
                'raw' => mb_substr($content, 0, 400),
            ]);
            return $this->fallback($goal, $tone, $audience);
        }

        return $parsed;
    }

    private function systemPrompt(): string
    {
        $tokens = implode(', ', array_keys(self::TOKEN_HINTS));

        return "You are an email marketing copywriter for FLiK — an Indonesian streaming platform.\n"
            . "Write copy in INDONESIAN, brand voice: warm, cinematic, slightly aspirational.\n"
            . "You may use personalization tokens which the mail server substitutes per recipient: {$tokens}.\n"
            . "Output WAJIB strict JSON tanpa markdown fence. Schema (semua key wajib ada):\n"
            . "{\n"
            . '  "subjects":   ["string", "string", "string"],   // 3 subject lines, masing-masing < 60 karakter, tanpa kutip' . "\n"
            . '  "preheader":  "string",                          // 1 kalimat preview, < 110 karakter' . "\n"
            . '  "html_body":  "string",                          // HTML siap kirim (paragraf <p>, satu link CTA <a>)' . "\n"
            . '  "plain_body": "string"                           // versi plain text untuk fallback' . "\n"
            . "}\n"
            . "Aturan body: 120-220 kata, 2-4 paragraf, ada satu CTA jelas, hindari emoji berlebihan.";
    }

    private function userPrompt(string $goal, string $tone, string $audience): string
    {
        $audienceLine = $audience !== ''
            ? "Audience description: {$audience}"
            : "Audience description: pelanggan FLiK aktif";

        return "Goal: {$goal}\n"
             . "Tone: {$tone}\n"
             . "{$audienceLine}\n\n"
             . "Tulis draft email kampanye sekarang. Output strict JSON saja.";
    }

    /**
     * Try to recover JSON from the AI response — tolerates leading text,
     * trailing prose, and accidental markdown fences. Returns null if
     * the response can't be coerced into the expected shape.
     *
     * @return DraftShape|null
     */
    private function parseStrictJson(string $raw): ?array
    {
        if ($raw === '') return null;

        // Strip code fences if model added them.
        $raw = preg_replace('/```(?:json)?\s*|\s*```/i', '', $raw) ?? $raw;
        $raw = trim($raw);

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            // Fallback: try to extract the first top-level JSON object.
            if (preg_match('/\{.*\}/s', $raw, $m)) {
                $data = json_decode($m[0], true);
            }
        }

        if (!is_array($data)) {
            return null;
        }

        $subjects = $data['subjects'] ?? [];
        if (!is_array($subjects)) {
            return null;
        }

        $cleanSubjects = [];
        foreach ($subjects as $s) {
            if (is_string($s) && trim($s) !== '') {
                $cleanSubjects[] = mb_substr(trim($s), 0, 200);
            }
        }
        // Always return 3 subjects — pad with the first one if model
        // returned fewer (UI expects a fixed-size array).
        while (count($cleanSubjects) < 3) {
            $cleanSubjects[] = $cleanSubjects[0] ?? 'Ada yang baru di FLiK untukmu';
        }
        $cleanSubjects = array_slice($cleanSubjects, 0, 3);

        return [
            'subjects'   => $cleanSubjects,
            'preheader'  => mb_substr((string) ($data['preheader'] ?? ''), 0, 160),
            'html_body'  => (string) ($data['html_body'] ?? ''),
            'plain_body' => (string) ($data['plain_body'] ?? ''),
        ];
    }

    // ── Fallback ──────────────────────────────────────────────

    /**
     * @return DraftShape
     */
    private function fallback(string $goal, string $tone, string $audience): array
    {
        $audienceLine = $audience !== '' ? " untuk {$audience}" : '';

        $body = "Halo {{first_name}},\n\n"
              . "Kami punya kabar baru dari FLiK{$audienceLine}.\n\n"
              . "{$goal}\n\n"
              . "Buka aplikasi FLiK sekarang untuk mulai menonton.";

        $html = '<p>Halo {{first_name}},</p>'
              . '<p>Kami punya kabar baru dari FLiK' . htmlspecialchars($audienceLine, ENT_QUOTES, 'UTF-8') . '.</p>'
              . '<p>' . htmlspecialchars($goal, ENT_QUOTES, 'UTF-8') . '</p>'
              . '<p><a href="' . url('/') . '" style="display:inline-block;padding:12px 22px;background:#C5A55A;color:#000;text-decoration:none;border-radius:8px;font-weight:600">Mulai Menonton</a></p>';

        return [
            'subjects' => [
                'Ada yang baru di FLiK, {{first_name}}',
                'FLiK punya sesuatu untukmu hari ini',
                'Selamat datang kembali di FLiK',
            ],
            'preheader'  => mb_substr($goal, 0, 110),
            'html_body'  => $html,
            'plain_body' => $body,
        ];
    }

    private function normalizeTone(string $tone): string
    {
        $tone = strtolower(trim($tone));
        return in_array($tone, self::TONES, true) ? $tone : 'warm';
    }
}
