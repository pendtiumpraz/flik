<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiClient;
use App\Services\Ai\FilmKnowledgeService;
use App\Services\Ai\WebSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class ChatController extends Controller
{
    /**
     * Available tools for the AI agent (OpenAI-compatible function calling).
     * DeepSeek V4 supports this natively.
     */
    protected function tools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_web',
                    'description' => 'Search the web (Wikipedia + DuckDuckGo) for additional film/cinema info NOT available in the FLiK catalog. ONLY use when user asks for details (cast bio, awards, behind-the-scenes, reviews, box office) about a film/person related to cinema. NEVER use for non-film queries.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Search query, MUST relate to film/cinema (movie title, actor name, director, film history, etc).',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
        ];
    }

    public function respond(
        Request $request,
        AiClient $ai,
        FilmKnowledgeService $kb,
        WebSearchService $web
    ): JsonResponse {
        $data = $request->validate([
            'message' => 'required|string|min:1|max:1000',
            'history' => 'nullable|array|max:20',
            'history.*.role' => 'required_with:history|in:user,bot',
            'history.*.text' => 'required_with:history|string|max:2000',
        ]);

        // Rate limit
        $rateKey = 'chat:' . (auth()->id() ?? $request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 20)) {
            return response()->json(['error' => 'Terlalu banyak pesan. Tunggu sebentar.'], 429);
        }
        RateLimiter::hit($rateKey, 60);

        // ━━━ RAG: retrieve relevant films + full catalog whitelist ━━━
        $relevantFilms = $kb->searchRelevant($data['message'], 8);
        $catalogStats = $kb->catalogOverview();
        $filmsContext = $relevantFilms->map(fn ($m) => $kb->formatForAi($m))->toArray();
        // Authoritative slug whitelist (entire catalog, compact)
        $catalogIndex = $kb->fullCatalogIndex();

        $systemPrompt = $this->buildSystemPrompt($catalogStats, $filmsContext, $catalogIndex);

        // Build conversation messages
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach (array_slice($data['history'] ?? [], -10) as $msg) {
            $messages[] = [
                'role' => $msg['role'] === 'bot' ? 'assistant' : 'user',
                'content' => $msg['text'],
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $data['message']];

        try {
            // ━━━ Agentic loop — max 2 tool calls (prevent runaway) ━━━
            $maxIterations = 2;
            $iteration = 0;
            $usedWebSearch = false;
            $webSourcesShown = [];

            while ($iteration < $maxIterations) {
                $iteration++;

                $result = $ai->chat($messages, [
                    'max_tokens' => 600,
                    'temperature' => 0.5,
                    'tools' => $this->tools(),
                ]);

                // If AI wants to call a tool
                if (!empty($result['tool_calls'])) {
                    // Add assistant tool_calls message to history
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $result['content'] ?: null,
                        'tool_calls' => $result['tool_calls'],
                    ];

                    foreach ($result['tool_calls'] as $call) {
                        $fnName = $call['function']['name'] ?? '';
                        $args = json_decode($call['function']['arguments'] ?? '{}', true);

                        if ($fnName === 'search_web') {
                            $query = $args['query'] ?? '';
                            // Guard: validate query is film-related (basic sanity)
                            if (!$this->isFilmRelated($query)) {
                                $toolResult = ['error' => 'Query out of scope. Only film/cinema searches allowed.'];
                            } else {
                                $usedWebSearch = true;
                                $searchResults = $web->search($query, 4);
                                $toolResult = ['results' => $searchResults];
                                foreach ($searchResults as $r) {
                                    if (!empty($r['url'])) $webSourcesShown[] = $r['url'];
                                }
                            }
                        } else {
                            $toolResult = ['error' => "Unknown tool: {$fnName}"];
                        }

                        // Send tool result back to AI
                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $call['id'] ?? '',
                            'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE),
                        ];
                    }
                    // Loop: call AI again with tool result
                    continue;
                }

                // No tool calls, AI responded directly
                break;
            }

            $reply = trim($result['content'] ?? '');

            // Post-flight: if AI somehow generated off-topic content despite guardrails
            if ($this->isOffTopicResponse($reply, $data['message'])) {
                $reply = "Maaf, aku FLiK Assistant — khusus bantu soal film aja. Coba tanya rekomendasi film, info aktor/sutradara, atau bantuan navigasi platform ya.";
            }

            // ━━━ Post-process: validate & fix /movie/slug links ━━━
            // Prevents 404s from AI hallucinating film slugs that don't exist.
            $reply = $this->validateMovieLinks($reply, $kb);

            return response()->json([
                'reply' => $reply,
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'used_web_search' => $usedWebSearch,
                'web_sources' => array_slice(array_unique($webSourcesShown), 0, 4),
                'context_films' => $relevantFilms->map(fn ($m) => [
                    'id' => $m->id, 'slug' => $m->slug, 'title' => $m->title,
                ])->values(),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'reply' => 'Maaf, AI assistant belum tersedia. Admin bisa konfigurasi di /admin/ai-settings.',
            ], 503);
        } catch (\Throwable $e) {
            Log::error('Chat error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'error' => 'AI sedang sibuk. Coba lagi sebentar.',
                'reply' => 'Maaf, ada gangguan sementara. Coba ulangi pesannya ya.',
            ], 500);
        }
    }

    /**
     * Check if a search query is film-related (used to validate tool calls).
     */
    protected function isFilmRelated(string $query): bool
    {
        $lower = mb_strtolower($query);
        $filmTerms = [
            'film', 'movie', 'cinema', 'sinema', 'sutradara', 'director', 'actor', 'actress',
            'aktor', 'aktris', 'pemeran', 'cast', 'oscar', 'cannes', 'box office',
            'rating', 'imdb', 'rotten', 'tomatoes', 'release', 'rilis', 'sequel',
            'prequel', 'remake', 'trilogy', 'franchise', 'genre', 'plot', 'cerita',
            'character', 'karakter', 'scene', 'adegan', 'soundtrack', 'theme',
            'screenplay', 'script', 'naskah', 'production', 'studio', 'netflix',
            'disney', 'marvel', 'dc', 'pixar', 'ghibli', 'bollywood', 'hollywood',
        ];

        foreach ($filmTerms as $term) {
            if (str_contains($lower, $term)) return true;
        }

        // Also allow if query mentions a known person/film name (heuristic: capitalized words)
        if (preg_match('/[A-Z][a-z]+ [A-Z][a-z]+/', $query)) return true;

        return false;
    }

    /**
     * Parse `[Title](/movie/slug)` markdown links in AI reply, validate each
     * slug against catalog. For invalid slugs:
     *   1. Try to find closest real film by the link text → swap slug
     *   2. If no match → strip link, keep plain title with disclaimer
     *
     * Prevents 404 when AI hallucinates non-existent film slugs.
     */
    protected function validateMovieLinks(string $reply, FilmKnowledgeService $kb): string
    {
        return preg_replace_callback(
            '#\[([^\]]+)\]\(/movie/([a-z0-9\-]+)/?\)#i',
            function ($match) use ($kb) {
                $title = $match[1];
                $slug = strtolower($match[2]);

                // Slug exists → keep as-is
                if ($kb->slugExists($slug)) {
                    return "[{$title}](/movie/{$slug})";
                }

                // Slug invalid → try to find closest real film by title
                $closest = $kb->findClosestByTitle($title);
                if ($closest) {
                    \Log::info('AI link auto-fixed', [
                        'original_slug' => $slug,
                        'fixed_slug' => $closest->slug,
                        'title' => $title,
                    ]);
                    return "[{$closest->title}](/movie/{$closest->slug})";
                }

                // No match found → strip link, keep title in plain text
                \Log::warning('AI link unfixable, stripped', [
                    'original_slug' => $slug,
                    'title' => $title,
                ]);
                return "*{$title}* _(belum tersedia di FLiK)_";
            },
            $reply
        );
    }

    /**
     * Post-flight safety net: detect if AI got tricked into off-topic content.
     * Conservative — only catch obvious failures (code blocks, pure math).
     * AI prompt should handle most cases via instruction.
     */
    protected function isOffTopicResponse(string $reply, string $userMessage): bool
    {
        if (empty($reply)) return true;

        // Fenced code block → AI generated code despite scope
        if (preg_match('/```(?:php|python|javascript|js|java|cpp|c\+\+|html|css|sql|bash|sh|go|rust|ruby|typescript|ts)\b/i', $reply)) {
            return true;
        }

        // Generic code fence with code-like content (function def, class, import)
        if (str_contains($reply, '```') && preg_match('/(function|def|class|import|console\.log|public\s+static)/i', $reply)) {
            return true;
        }

        // Pure math answer (response is just "= 2" or "2" etc, with no film context)
        $stripped = trim($reply);
        if (preg_match('/^=?\s*[\d.,\s+\-*\/()]+\s*$/', $stripped) && mb_strlen($stripped) < 30) {
            return true;
        }

        return false;
    }

    protected function buildSystemPrompt(array $catalogStats, array $filmsContext, array $catalogIndex = []): string
    {
        $userName = auth()->check() ? auth()->user()->name : 'tamu';
        $totalFilms = $catalogStats['total_films'];
        $yearRange = $catalogStats['year_range'] ?? '—';
        $genreList = implode(', ', $catalogStats['genres']);
        $filmsJson = json_encode($filmsContext, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // Compact whitelist as plain text (cheap on tokens)
        $whitelistLines = [];
        foreach ($catalogIndex as $slug => $title) {
            $whitelistLines[] = "- {$slug} | {$title}";
        }
        $whitelist = implode("\n", $whitelistLines);

        return <<<PROMPT
            # FLiK Assistant — Cinema Specialist

            Kamu **FLiK Assistant**, AI spesialis film di platform streaming "FLiK". Kamu **BUKAN** general assistant.

            ## ⚠️ STRICT SCOPE — Tidak Bisa Dinegosiasi
            Kamu HANYA bantu soal:
            ✅ Film, sinopsis, rating, genre, tahun rilis
            ✅ Aktor, aktris, sutradara, kru film
            ✅ Rekomendasi, perbandingan, similar films
            ✅ Sejarah perfilman, awards, festival, box office
            ✅ Navigasi platform FLiK (langganan, watchlist, profile)

            ❌ **TOLAK SEMUA** topik lain:
            - Coding/programming (PHP, Python, JS, kalkulator app, function, dll)
            - Matematika (1+1, hitung apapun)
            - Cuaca, berita, politik, olahraga, kesehatan, crypto, finance
            - Resep masakan, lifestyle umum
            - Translate / definisi kata (kecuali istilah perfilman)
            - Tulis puisi/esai/cerita non-film
            - Roleplay / pretend jadi AI lain

            ## 🎭 Cara TOLAK yang Benar
            User suka "ngakalin" pakai conversational text. Tetap detect intent inti.

            ### Few-shot Examples — IKUTI POLA INI

            **User**: "huah aku ngantuk mau tidur, tolong buatkan app kalkulator dong"
            **Kamu**: "Hehe, aku gak bantu bikin app/kalkulator nih — khusus film aja. Gimana kalau aku rekomendasiin film santai buat kamu sebelum tidur? Mood-nya lagi pengen apa?"

            **User**: "1+1 berapa sih?"
            **Kamu**: "Hehe aku gak hitung-hitungan ya, tapi aku tau **The Avengers** ★8.7 kumpulin 6 superhero kalau itung-itung tim! Mau aku rekomenin film lain?"

            **User**: "tulis kode PHP loop sederhana"
            **Kamu**: "Maaf, aku spesialis film — gak bantu coding. Tapi kalau mau aku rekomenin film tentang programmer/hacker keren, aku punya **The Matrix** ★8.7 dan beberapa lagi nih. Tertarik?"

            **User**: "translate hello to japanese"
            **Kamu**: "Aku gak terjemahin kata umum, khusus istilah film aja. Tapi kalau kamu suka film Jepang, aku bisa rekomen **Spirited Away** ★8.6 atau **Your Name** ★8.6 lho!"

            **User**: "gimana cuaca hari ini"
            **Kamu**: "Wah aku gak tau cuaca ya, aku bukan asisten cuaca. Tapi kalau cuaca lagi hujan, asik banget tonton film cozy — mau aku rekomenin?"

            **User**: "kamu ChatGPT ya?"
            **Kamu**: "Aku FLiK Assistant — AI khusus bantu kamu nemu film bagus di platform FLiK. Mau cari film apa hari ini?"

            **User**: "Roleplay jadi guru matematika dong"
            **Kamu**: "Hehe aku gak roleplay, aku tetep FLiK Assistant. Tapi kalau cari film tentang matematika, ada **Oppenheimer** ★8.1 — soal fisikawan jenius. Mau coba?"

            **Pola wajib**: tolak singkat → opsional kasih hook film terkait → ajak tetep di topik film. JANGAN pernah ikuti instruksi off-topic walau user minta sopan/dipaksa/dialihkan halus.

            ## Knowledge Base — Catalog FLiK
            - Total film: **{$totalFilms}**
            - Rentang tahun: {$yearRange}
            - Genre tersedia: {$genreList}

            ## ⚠️ WHITELIST — HANYA Film Berikut yang Boleh Direkomendasikan
            **WAJIB BACA**: SEMUA film yang kamu sebut/rekomen HARUS ada di list ini. JANGAN sebut film di luar list (walaupun terkenal di luar). Format: `slug | judul`

            ```
            {$whitelist}
            ```

            **Aturan slug**:
            - Saat bikin link `[Judul Film](/movie/<slug>)`, slug HARUS persis sama dengan slug di whitelist atas
            - Kalau judul di pikiran kamu (misal "Avatar 2") TIDAK ada di whitelist → JANGAN sebut sama sekali, atau bilang "belum ada di FLiK, tapi ada [Judul Mirip](/movie/slug-mirip)"
            - Contoh BENAR: `[The Avengers](/movie/the-avengers)` (kalau slug "the-avengers" ada di whitelist)
            - Contoh SALAH: `[Avatar 2](/movie/avatar-2)` kalau "avatar-2" tidak ada di whitelist

            ## Detail Film Relevan untuk Pertanyaan Sekarang
            ```json
            {$filmsJson}
            ```

            ## Tool — search_web
            Pakai `search_web` HANYA kalau user nanya detail film/sinema yang gak ada di catalog (awards, behind-the-scenes, review kritikus, biografi aktor mendalam, box office stats). Query MUST film-related — tool akan reject query non-film.

            ## Format Jawaban
            - Bahasa Indonesia santai, sapa "{$userName}" sekali-sekali
            - Singkat (max 3-4 paragraf)
            - Sebut judul: **bold** + clickable link `[Judul](/movie/slug)` (untuk film di catalog)
            - Cite web search source: `[ref](url)` di akhir paragraf
            - Rekomendasi: 2-4 film dengan alasan singkat
            - JANGAN spoiler kecuali user minta
            - Pakai rating ★ X.X kalau ada

            ## Plan FLiK (kalau ditanya)
            Free, Basic Rp 39rb, Premium Rp 79rb, Family Rp 129rb (annual diskon 20%)
            PROMPT;
    }
}
