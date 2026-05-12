<?php

namespace App\Services\Ai\Tasks;

use App\Models\Coin;
use App\Models\User;
use App\Models\WatchHistory;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;

/**
 * AI-driven email copy personalizer.
 *
 * Builds a lightweight user profile from WatchHistory (genres watched, completion rate),
 * gamification (XP/level, coin balance), and subscription tier — feeds it to the configured
 * AiClient as system+user messages to produce subject lines and email bodies in Indonesian.
 *
 * Designed to degrade gracefully: if the AI provider is unavailable, falls back to a
 * deterministic template-based output so the caller (e.g. scheduled digest) never crashes.
 *
 * Supported intents:
 *   - 'win_back'                — re-engage users who haven't watched recently
 *   - 'new_release'             — announce a new title that matches their taste
 *   - 'milestone_celebration'   — celebrate level-up, streak, achievement
 *   - 'subscription_renewal'    — remind renewal / promote upgrade
 *   - 'recommendation_digest'   — weekly digest with top picks
 */
class EmailPersonalizer
{
    public const INTENT_WIN_BACK = 'win_back';
    public const INTENT_NEW_RELEASE = 'new_release';
    public const INTENT_MILESTONE = 'milestone_celebration';
    public const INTENT_RENEWAL = 'subscription_renewal';
    public const INTENT_DIGEST = 'recommendation_digest';

    public const INTENTS = [
        self::INTENT_WIN_BACK,
        self::INTENT_NEW_RELEASE,
        self::INTENT_MILESTONE,
        self::INTENT_RENEWAL,
        self::INTENT_DIGEST,
    ];

    /**
     * Maximum subject-line length (chars) — enforced after AI generation.
     */
    public const SUBJECT_MAX_LEN = 60;

    public function __construct(
        protected ?AiClient $ai = null,
    ) {}

    // ──────────────────────────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────────────────────────

    /**
     * Generate a subject line tailored to the user.
     *
     * $context may include:
     *   - intent   (string)   — one of self::INTENTS (defaults to 'recommendation_digest')
     *   - movies   (array)    — list of movie titles relevant to this email
     *   - tone_hint(string)
     *   - cta      (string)   — call-to-action
     */
    public function personalizeSubject(User $user, string $template, array $context = []): string
    {
        $intent = $this->normalizeIntent($context['intent'] ?? self::INTENT_DIGEST);
        $profile = $this->buildUserProfile($user);

        $systemPrompt = $this->systemPrompt() . "\n\n"
            . "TASK: Write ONE email subject line in Indonesian. "
            . "Hard limits: max " . self::SUBJECT_MAX_LEN . " characters, no quotes, no emojis unless cinematic, "
            . "no markdown. Output ONLY the subject text, nothing else.";

        $userPrompt = $this->buildUserPromptForSubject($profile, $intent, $template, $context);

        $generated = $this->callAi($systemPrompt, $userPrompt, maxTokens: 80, temperature: 0.85);

        $subject = $this->cleanSubject($generated)
            ?: $this->fallbackSubject($user, $intent, $template, $context);

        // Enforce length cap, character-safe (mb).
        if (mb_strlen($subject) > self::SUBJECT_MAX_LEN) {
            $subject = rtrim(mb_substr($subject, 0, self::SUBJECT_MAX_LEN - 1)) . '…';
        }

        return $subject;
    }

    /**
     * Generate the full email body (plain text, ~150-200 words) tailored to the user.
     *
     * $context may include:
     *   - movies   (array<int, array{title:string, slug?:string, why?:string}>)
     *   - cta      (string)
     *   - cta_url  (string)
     *   - milestone(string) — e.g. "Level 5", "30-day streak"
     *   - plan_name(string)
     *   - expires_in_days(int)
     */
    public function personalizeBody(User $user, string $intent, array $context = []): string
    {
        $intent = $this->normalizeIntent($intent);
        $profile = $this->buildUserProfile($user);

        $systemPrompt = $this->systemPrompt() . "\n\n"
            . "TASK: Write a complete email body in Indonesian for the '{$intent}' campaign. "
            . "Hard limits: body must be UNDER 200 words. Use 2-4 short paragraphs. "
            . "Address the user by name in the first paragraph. Reference their viewing habits naturally. "
            . "End with a clear call-to-action. No markdown headings, no asterisks, no emojis. "
            . "Output ONLY the email body, no subject, no signature line, no quotes.";

        $userPrompt = $this->buildUserPromptForBody($profile, $intent, $context);

        $generated = $this->callAi($systemPrompt, $userPrompt, maxTokens: 480, temperature: 0.75);

        $body = $this->cleanBody($generated);

        if ($body === '') {
            return $this->fallbackBody($user, $intent, $context);
        }

        return $body;
    }

    /**
     * Expose user profile builder (useful for testing / digest command).
     *
     * @return array{
     *   id:int, name:string, email:string,
     *   top_genres: list<string>,
     *   recently_watched: list<string>,
     *   total_watched:int, completion_rate:int,
     *   level:int, xp:int, coins:int,
     *   subscription_tier:?string, subscription_active:bool,
     *   days_since_last_watch:?int
     * }
     */
    public function buildUserProfile(User $user): array
    {
        $histories = WatchHistory::with('movie.genres')
            ->where('user_id', $user->id)
            ->orderByDesc('last_watched_at')
            ->limit(50)
            ->get();

        // Top genres by watch count.
        $genreCounts = [];
        foreach ($histories as $h) {
            $movie = $h->movie;
            if (!$movie) continue;
            foreach ($movie->genres as $genre) {
                $name = (string) $genre->name;
                if ($name === '') continue;
                $genreCounts[$name] = ($genreCounts[$name] ?? 0) + 1;
            }
        }
        arsort($genreCounts);
        $topGenres = array_slice(array_keys($genreCounts), 0, 5);

        $recentlyWatched = $histories
            ->take(5)
            ->map(fn (WatchHistory $h) => (string) ($h->movie?->title ?? ''))
            ->filter(fn (string $t) => $t !== '')
            ->values()
            ->all();

        $totalWatched = $histories->count();
        $completed = $histories->where('completed', true)->count();
        $completionRate = $totalWatched > 0 ? (int) round(($completed / $totalWatched) * 100) : 0;

        $lastWatch = $histories->first()?->last_watched_at;
        $daysSinceLastWatch = $lastWatch ? (int) $lastWatch->diffInDays(now()) : null;

        $level = $user->level; // hasOne — may be null
        $plan = $user->currentPlan();

        return [
            'id'                    => (int) $user->id,
            'name'                  => (string) $user->name,
            'email'                 => (string) $user->email,
            'top_genres'            => array_values(array_map('strval', $topGenres)),
            'recently_watched'      => $recentlyWatched,
            'total_watched'         => $totalWatched,
            'completion_rate'       => $completionRate,
            'level'                 => (int) ($level->level ?? 1),
            'xp'                    => (int) ($level->xp ?? 0),
            'coins'                 => $this->safeCoinBalance($user),
            'subscription_tier'     => $plan?->name,
            'subscription_active'   => $user->hasActiveSubscription(),
            'days_since_last_watch' => $daysSinceLastWatch,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Prompt construction
    // ──────────────────────────────────────────────────────────────────

    protected function systemPrompt(): string
    {
        return "You're an email marketing specialist for Indonesian streaming platform FLiK. "
            . "Write personalized email copy in Indonesian. Tone: warm, friendly, slightly cinematic. "
            . "Address user by name. Reference their viewing habits naturally. "
            . "Keep subject under 60 chars, body under 200 words.";
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $context
     */
    protected function buildUserPromptForSubject(array $profile, string $intent, string $template, array $context): string
    {
        $profileJson = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contextJson = json_encode($this->sanitizeContext($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "Intent: {$intent}\n"
            . "Template/hint: {$template}\n"
            . "User profile: {$profileJson}\n"
            . "Extra context: {$contextJson}\n\n"
            . "Write the subject line now. Indonesian. Under " . self::SUBJECT_MAX_LEN . " characters.";
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $context
     */
    protected function buildUserPromptForBody(array $profile, string $intent, array $context): string
    {
        $profileJson = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contextJson = json_encode($this->sanitizeContext($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $guidance = match ($intent) {
            self::INTENT_WIN_BACK   => "User hasn't watched for a while — gently invite them back, mention a genre they love.",
            self::INTENT_NEW_RELEASE => "Highlight the new release(s) in context.movies and explain why it matches their taste.",
            self::INTENT_MILESTONE  => "Celebrate their milestone (context.milestone). Make them feel rewarded.",
            self::INTENT_RENEWAL    => "Remind about subscription renewal. Be helpful, not pushy.",
            self::INTENT_DIGEST     => "Weekly digest: recap their week, recommend 2-3 films from context.movies.",
            default                 => "Write a relevant FLiK email.",
        };

        return "Intent: {$intent}\n"
            . "Guidance: {$guidance}\n"
            . "User profile: {$profileJson}\n"
            . "Email context: {$contextJson}\n\n"
            . "Write the email body now. Indonesian. Under 200 words. 2-4 paragraphs. End with a call-to-action.";
    }

    // ──────────────────────────────────────────────────────────────────
    //  AI call + fallbacks
    // ──────────────────────────────────────────────────────────────────

    protected function callAi(string $systemPrompt, string $userPrompt, int $maxTokens, float $temperature): string
    {
        if (!$this->ai) {
            return '';
        }

        try {
            $response = $this->ai->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ], [
                'max_tokens'  => $maxTokens,
                'temperature' => $temperature,
            ]);

            return trim((string) ($response['content'] ?? ''));
        } catch (\Throwable $e) {
            Log::warning('EmailPersonalizer AI call failed', [
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function fallbackSubject(User $user, string $intent, string $template, array $context): string
    {
        $name = $this->firstName($user);
        $movieTitle = $this->firstMovieTitle($context);

        return match ($intent) {
            self::INTENT_WIN_BACK    => "Kangen film favoritmu, {$name}?",
            self::INTENT_NEW_RELEASE => $movieTitle !== ''
                ? "Baru rilis di FLiK: {$movieTitle}"
                : "Ada film baru buat kamu, {$name}",
            self::INTENT_MILESTONE   => "Selamat, {$name}! Pencapaian baru menantimu",
            self::INTENT_RENEWAL     => "Lanjutkan petualangan FLiK-mu, {$name}",
            self::INTENT_DIGEST      => "Rekomendasi minggu ini buat {$name}",
            default                  => $template !== ''
                ? mb_substr($template, 0, self::SUBJECT_MAX_LEN)
                : "Halo {$name}, ada kabar dari FLiK",
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function fallbackBody(User $user, string $intent, array $context): string
    {
        $name = $this->firstName($user);
        $movies = $this->extractMovieTitles($context);
        $cta = (string) ($context['cta'] ?? 'Mulai nonton di FLiK');
        $movieLine = !empty($movies)
            ? "Pilihan kami minggu ini: " . implode(', ', array_slice($movies, 0, 3)) . "."
            : "";

        return match ($intent) {
            self::INTENT_WIN_BACK => trim(
                "Halo {$name},\n\n"
                . "Sudah beberapa waktu sejak kamu terakhir menyalakan layar FLiK. Banyak cerita baru menunggu di rumah sinema kami.\n\n"
                . $movieLine . "\n\n"
                . "{$cta}."
            ),
            self::INTENT_NEW_RELEASE => trim(
                "Halo {$name},\n\n"
                . "Rilisan terbaru sudah tayang di FLiK dan kami pikir kamu pasti suka.\n\n"
                . $movieLine . "\n\n"
                . "{$cta}."
            ),
            self::INTENT_MILESTONE => trim(
                "Selamat, {$name}!\n\n"
                . "Kamu baru saja mencapai " . (string) ($context['milestone'] ?? 'tonggak baru') . " di FLiK. "
                . "Penonton sejati seperti kamu yang membuat rumah sinema ini hidup.\n\n"
                . "{$cta}."
            ),
            self::INTENT_RENEWAL => trim(
                "Halo {$name},\n\n"
                . "Langganan FLiK-mu akan segera berakhir. Jangan sampai kehilangan akses ke film favorit dan rilisan terbaru.\n\n"
                . "{$cta}."
            ),
            default => trim(
                "Halo {$name},\n\n"
                . "Ini sapaan singkat dari kami. " . $movieLine . "\n\n"
                . "{$cta}."
            ),
        };
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────

    protected function normalizeIntent(string $intent): string
    {
        $intent = trim(strtolower($intent));
        return in_array($intent, self::INTENTS, true) ? $intent : self::INTENT_DIGEST;
    }

    protected function cleanSubject(string $raw): string
    {
        if ($raw === '') return '';

        // Strip code fences, quotes, leading "Subject:" labels.
        $raw = preg_replace('/^```[\w]*\s*|\s*```$/im', '', $raw) ?? $raw;
        $raw = preg_replace('/^(subject|subjek|judul)\s*:\s*/i', '', trim($raw)) ?? $raw;
        $raw = trim($raw, " \t\n\r\0\x0B\"'`“”‘’");

        // Subject must be single line.
        $first = strtok($raw, "\r\n");
        return is_string($first) ? trim($first) : '';
    }

    protected function cleanBody(string $raw): string
    {
        if ($raw === '') return '';

        // Strip wrapping code fences if model added them.
        $raw = preg_replace('/^```[\w]*\s*|\s*```$/im', '', $raw) ?? $raw;
        // Remove markdown bold/italic markers but keep text.
        $raw = preg_replace('/\*+([^*]+)\*+/u', '$1', $raw) ?? $raw;
        // Collapse 3+ blank lines.
        $raw = preg_replace("/\n{3,}/", "\n\n", $raw) ?? $raw;

        return trim($raw);
    }

    protected function firstName(User $user): string
    {
        $name = trim((string) $user->name);
        if ($name === '') return 'Sobat FLiK';
        $first = strtok($name, ' ');
        return is_string($first) && $first !== '' ? $first : $name;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    protected function extractMovieTitles(array $context): array
    {
        $movies = $context['movies'] ?? [];
        if (!is_array($movies)) return [];

        $titles = [];
        foreach ($movies as $m) {
            if (is_string($m) && $m !== '') {
                $titles[] = $m;
            } elseif (is_array($m) && isset($m['title']) && is_string($m['title']) && $m['title'] !== '') {
                $titles[] = $m['title'];
            }
        }
        return $titles;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function firstMovieTitle(array $context): string
    {
        $titles = $this->extractMovieTitles($context);
        return $titles[0] ?? '';
    }

    /**
     * Drop anything we don't want to leak into the prompt.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function sanitizeContext(array $context): array
    {
        unset($context['password'], $context['token'], $context['api_key']);
        return $context;
    }

    protected function safeCoinBalance(User $user): int
    {
        try {
            return Coin::balanceFor((int) $user->id);
        } catch (\Throwable) {
            return 0;
        }
    }
}
