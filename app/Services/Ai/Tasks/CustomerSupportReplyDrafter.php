<?php

namespace App\Services\Ai\Tasks;

use App\Services\Ai\AiClient;
use Illuminate\Support\Str;

/**
 * AI-powered customer support reply drafter.
 *
 * Generates a Bahasa Indonesia draft reply for a support ticket / email.
 * The output is intended for ADMIN REVIEW — never auto-sent. Tone is warm
 * professional, empathetic, and avoids false promises (no SLA guarantees,
 * no refund commitments unless the category explicitly is 'refund').
 *
 * Categories steer the persona / next-step guidance:
 *   - billing        : payment issues, invoice questions
 *   - technical      : streaming errors, app crashes, video quality
 *   - content_issue  : missing subtitles, broken episodes, content takedowns
 *   - account        : login, profile, parental controls
 *   - refund         : refund / cancellation / chargeback
 *   - general        : anything else (default)
 */
class CustomerSupportReplyDrafter
{
    public const TARGET_MIN_WORDS = 100;
    public const TARGET_MAX_WORDS = 200;

    public const CATEGORIES = [
        'billing',
        'technical',
        'content_issue',
        'account',
        'refund',
        'general',
    ];

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Draft a customer support reply.
     *
     * @param  string  $userQuery      The raw user message / complaint.
     * @param  array   $userContext    Optional user metadata: ['name' => ?, 'plan' => ?, 'subscription_status' => ?, 'account_age_days' => ?, 'last_payment_at' => ?, ...]
     * @param  string  $issueCategory  One of self::CATEGORIES (defaults to 'general').
     * @return string  Indonesian reply draft (admin should review/edit before sending).
     */
    public function draft(string $userQuery, array $userContext = [], string $issueCategory = 'general'): string
    {
        $userQuery     = trim($userQuery);
        $issueCategory = in_array($issueCategory, self::CATEGORIES, true) ? $issueCategory : 'general';

        if ($userQuery === '') {
            return $this->fallbackDraft($issueCategory);
        }

        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => $this->buildSystemPrompt($issueCategory)],
                    ['role' => 'user',   'content' => $this->buildUserPrompt($userQuery, $userContext, $issueCategory)],
                ],
                options: [
                    'temperature' => 0.6,
                    'max_tokens'  => 600,
                ],
            );

            $draft = trim((string) ($response['content'] ?? ''));
            if ($draft === '') {
                return $this->fallbackDraft($issueCategory);
            }

            return $this->postProcess($draft);
        } catch (\Throwable $e) {
            \Log::warning('CustomerSupportReplyDrafter failed — returning fallback', [
                'category' => $issueCategory,
                'error'    => $e->getMessage(),
            ]);
            return $this->fallbackDraft($issueCategory);
        }
    }

    protected function buildSystemPrompt(string $category): string
    {
        $base = 'You\'re a customer support agent for Indonesian streaming platform FLiK ("FLiK — Rumah Sinema Indonesia"). '
            . 'Tulis draft balasan dalam Bahasa Indonesia yang HELPFUL, EMPATIK, dan PROFESIONAL HANGAT. '
            . 'Aturan WAJIB:'
            . ' (1) Sapa user dengan ramah (gunakan nama jika ada di konteks).'
            . ' (2) Akui keluhan/pertanyaan mereka secara spesifik (jangan generic).'
            . ' (3) Berikan langkah konkrit selanjutnya — minimal 2 langkah.'
            . ' (4) JANGAN buat janji palsu (no SLA spesifik, no refund guarantee kecuali kategori refund).'
            . ' (5) Tutup dengan tanda tangan ramah ("Salam hangat, Tim FLiK").'
            . ' (6) Panjang ' . self::TARGET_MIN_WORDS . '-' . self::TARGET_MAX_WORDS . ' kata.'
            . ' (7) JANGAN gunakan placeholder kurawal seperti {nama} atau [nama] — isi langsung atau hilangkan.'
            . ' (8) Output HANYA teks balasan, tanpa subject, tanpa header email, tanpa code fence.';

        return $base . ' ' . match ($category) {
            'billing' => 'Kategori: BILLING. Persona: tenang, detail-oriented. '
                . 'Suggested next steps: konfirmasi metode pembayaran, cek invoice di dashboard, sarankan retry payment, '
                . 'arahkan ke email billing@flik.id untuk eskalasi. JANGAN janjikan refund instan.',

            'technical' => 'Kategori: TECHNICAL. Persona: troubleshooter sabar. '
                . 'Suggested next steps: clear cache, cek koneksi, restart app, update aplikasi ke versi terbaru, '
                . 'tanya device/browser yang dipakai untuk diagnosa lebih dalam.',

            'content_issue' => 'Kategori: CONTENT ISSUE. Persona: mengerti pentingnya pengalaman menonton. '
                . 'Suggested next steps: konfirmasi judul + episode + bahasa subtitle, sampaikan akan diteruskan ke tim content QA, '
                . 'beri estimasi ETA fix yang realistis (1-3 hari kerja, JANGAN spesifik jam).',

            'account' => 'Kategori: ACCOUNT. Persona: aware soal privasi & keamanan. '
                . 'Suggested next steps: verifikasi email/identitas terkait, sarankan reset password jika relevan, '
                . 'arahkan ke pengaturan profil, ingatkan untuk JANGAN share kredensial.',

            'refund' => 'Kategori: REFUND. Persona: profesional, mengikuti policy. '
                . 'Boleh jelaskan policy refund standar (refund full jika belum ada konsumsi konten, prorate jika sudah). '
                . 'Minta info detail: tanggal pembayaran, alasan refund, metode pembayaran. '
                . 'Sebut waktu pemrosesan 5-14 hari kerja sebagai kisaran (BUKAN janji eksak).',

            default => 'Kategori: GENERAL. Persona: friendly all-rounder. '
                . 'Suggested next steps: minta info tambahan jika kurang konteks, arahkan ke /faq atau help center, '
                . 'sediakan opsi follow-up via email support.',
        };
    }

    protected function buildUserPrompt(string $userQuery, array $userContext, string $category): string
    {
        $lines = [
            'Draft balasan customer support untuk pesan berikut:',
            '',
            '=== PESAN USER ===',
            Str::limit($userQuery, 2000),
            '=== END PESAN ===',
            '',
            'Kategori issue : ' . $category,
        ];

        if (!empty($userContext)) {
            $lines[] = '';
            $lines[] = 'Konteks user:';
            foreach ($userContext as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $lines[] = '- ' . $key . ': ' . (string) $value;
            }
        }

        $lines[] = '';
        $lines[] = 'Aturan output:';
        $lines[] = '- Bahasa Indonesia hangat profesional.';
        $lines[] = '- ' . self::TARGET_MIN_WORDS . '-' . self::TARGET_MAX_WORDS . ' kata.';
        $lines[] = '- Sertakan minimal 2 langkah konkrit.';
        $lines[] = '- Tutup dengan "Salam hangat, Tim FLiK".';
        $lines[] = '- HANYA balas teks balasan. Tanpa subject. Tanpa code fence.';

        return implode("\n", $lines);
    }

    /**
     * Post-process AI output: strip code fences, common email-header lines,
     * and stray placeholder tokens like {nama} that the model may leave behind.
     */
    protected function postProcess(string $draft): string
    {
        // Strip code fences if present
        if (preg_match('/```(?:.*?)?\n(.+?)\n```/s', $draft, $m)) {
            $draft = $m[1];
        }

        // Strip common email-header lines models sometimes prepend
        $draft = preg_replace(
            '/^(?:Subject|Subjek|To|From|Dear)\s*:[^\n]*\n+/im',
            '',
            $draft,
        ) ?? $draft;

        // Remove leftover placeholder tokens like {nama_user} or [Nama]
        $draft = preg_replace('/\{\s*[A-Za-z0-9_\-\s]+\s*\}/u', '', $draft) ?? $draft;
        $draft = preg_replace('/\[\s*[A-Za-z0-9_\-\s]+\s*\]/u', '', $draft) ?? $draft;

        // Collapse 3+ newlines and trim trailing whitespace per line
        $draft = preg_replace("/\n{3,}/", "\n\n", $draft) ?? $draft;
        $draft = preg_replace("/[ \t]+\n/", "\n", $draft) ?? $draft;

        return trim($draft);
    }

    protected function fallbackDraft(string $category): string
    {
        $intro = 'Halo, terima kasih sudah menghubungi tim FLiK. Mohon maaf atas ketidaknyamanan yang Anda alami.';

        $body = match ($category) {
            'billing'       => 'Untuk masalah pembayaran, mohon kirimkan kepada kami: tanggal transaksi, metode pembayaran yang digunakan, dan screenshot bukti jika ada. Tim billing kami akan menelusuri transaksi Anda dan menghubungi kembali secepatnya.',
            'technical'     => 'Untuk membantu kami mendiagnosis masalah teknis Anda, mohon informasikan device dan versi aplikasi yang Anda gunakan, serta langkah persis yang menyebabkan error. Sementara itu, Anda bisa mencoba clear cache atau update aplikasi ke versi terbaru.',
            'content_issue' => 'Untuk masalah konten, mohon sebutkan judul film/episode dan bahasa subtitle yang bermasalah. Laporan Anda akan kami teruskan ke tim content QA untuk ditindaklanjuti.',
            'account'       => 'Untuk masalah akun, mohon verifikasi email yang terdaftar di FLiK. Demi keamanan, jangan pernah membagikan password Anda kepada siapa pun, termasuk staf kami.',
            'refund'        => 'Untuk permintaan refund, mohon kirimkan: tanggal pembayaran, metode pembayaran, dan alasan permohonan. Permohonan akan kami review sesuai policy dan biasanya selesai dalam 5-14 hari kerja.',
            default         => 'Mohon berikan informasi tambahan agar kami bisa membantu Anda lebih baik. Anda juga bisa mengecek halaman FAQ kami untuk jawaban cepat atas pertanyaan umum.',
        };

        return $intro . "\n\n" . $body . "\n\nSalam hangat,\nTim FLiK";
    }
}
