<?php

namespace App\Services\Ai;

use App\Models\Movie;
use Illuminate\Support\Str;

/**
 * RAG-style knowledge service: retrieve relevant films from catalog
 * to inject as context into AI prompts.
 *
 * Current implementation: keyword/LIKE search (works immediately, no extra deps).
 * Upgrade path (Phase 2): replace internals with pgvector + OpenAI embeddings
 * — keep same public interface.
 */
class FilmKnowledgeService
{
    /**
     * Stop words to ignore when extracting keywords from user query.
     */
    protected array $stopWords = [
        'aku', 'saya', 'kamu', 'dia', 'mereka', 'kita', 'ini', 'itu', 'yang', 'untuk',
        'dengan', 'apa', 'siapa', 'kenapa', 'kapan', 'dimana', 'gimana', 'bagaimana',
        'kasih', 'mau', 'mau', 'pengen', 'pingin', 'pgn', 'cari', 'carikan', 'rekom',
        'rekomendasi', 'rekomenin', 'film', 'movie', 'judul', 'tolong', 'bro', 'sis',
        'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should',
        'may', 'might', 'must', 'shall', 'can', 'need', 'dare', 'ought', 'used',
        'i', 'you', 'he', 'she', 'it', 'we', 'they', 'me', 'him', 'her', 'us', 'them',
        'my', 'your', 'his', 'its', 'our', 'their', 'this', 'that', 'these', 'those',
        'and', 'or', 'but', 'if', 'because', 'as', 'until', 'while', 'of', 'at', 'by',
        'for', 'about', 'against', 'between', 'into', 'through', 'during', 'before',
        'after', 'above', 'below', 'to', 'from', 'in', 'out', 'on', 'off', 'over',
        'under', 'again', 'further', 'then', 'once', 'movie', 'film', 'tonton', 'nonton',
    ];

    /**
     * Find films relevant to a free-text query.
     * Returns up to $limit films with relevance score.
     *
     * @return \Illuminate\Support\Collection<Movie>
     */
    public function searchRelevant(string $query, int $limit = 5): \Illuminate\Support\Collection
    {
        $keywords = $this->extractKeywords($query);

        if (empty($keywords)) {
            // No keywords → return popular fallback
            return Movie::with('genres', 'castMembers')
                ->orderByDesc('popularity')
                ->limit($limit)
                ->get();
        }

        // Build a relevance score query: match against title (×3), overview (×1), genre name (×2), cast name (×2)
        $movies = Movie::with('genres', 'castMembers')
            ->where(function ($q) use ($keywords) {
                foreach ($keywords as $kw) {
                    $q->orWhere('title', 'LIKE', "%{$kw}%")
                      ->orWhere('original_title', 'LIKE', "%{$kw}%")
                      ->orWhere('overview', 'LIKE', "%{$kw}%")
                      ->orWhereHas('genres', fn ($qg) => $qg->where('name', 'LIKE', "%{$kw}%"))
                      ->orWhereHas('castMembers', fn ($qc) => $qc->where('name', 'LIKE', "%{$kw}%"));
                }
            })
            ->limit($limit * 3) // overfetch then re-rank
            ->get();

        // Score & rank
        $scored = $movies->map(function (Movie $m) use ($keywords) {
            $score = 0;
            $titleLower = mb_strtolower($m->title . ' ' . ($m->original_title ?? ''));
            $overviewLower = mb_strtolower($m->overview ?? '');
            $genreLower = $m->genres->pluck('name')->map(fn ($n) => mb_strtolower($n))->join(' ');
            $castLower = $m->castMembers->pluck('name')->map(fn ($n) => mb_strtolower($n))->join(' ');

            foreach ($keywords as $kw) {
                $kw = mb_strtolower($kw);
                if (str_contains($titleLower, $kw)) $score += 5;
                if (str_contains($overviewLower, $kw)) $score += 1;
                if (str_contains($genreLower, $kw)) $score += 3;
                if (str_contains($castLower, $kw)) $score += 3;
            }

            // Bonus: high popularity & rating
            $score += ($m->popularity / 100) * 0.5;
            $score += ($m->vote_average / 10) * 0.3;

            $m->_relevance = $score;
            return $m;
        })
        ->sortByDesc('_relevance')
        ->take($limit)
        ->values();

        return $scored;
    }

    /**
     * Find movie by exact or fuzzy title match.
     */
    public function findByTitle(string $title): ?Movie
    {
        // Exact match first
        $movie = Movie::with('genres', 'castMembers')
            ->where('title', $title)
            ->orWhere('original_title', $title)
            ->orWhere('slug', Str::slug($title))
            ->first();

        if ($movie) return $movie;

        // Fuzzy LIKE
        return Movie::with('genres', 'castMembers')
            ->where('title', 'LIKE', "%{$title}%")
            ->orWhere('original_title', 'LIKE', "%{$title}%")
            ->orderByDesc('popularity')
            ->first();
    }

    /**
     * Recommend films similar to a given movie (genre + popularity overlap).
     */
    public function findSimilar(Movie $movie, int $limit = 5): \Illuminate\Support\Collection
    {
        $genreIds = $movie->genres->pluck('id')->toArray();

        return Movie::with('genres')
            ->where('id', '!=', $movie->id)
            ->whereHas('genres', fn ($q) => $q->whereIn('genres.id', $genreIds))
            ->withCount(['genres as matching_genres' => fn ($q) => $q->whereIn('genres.id', $genreIds)])
            ->orderByDesc('matching_genres')
            ->orderByDesc('popularity')
            ->limit($limit)
            ->get();
    }

    /**
     * Catalog overview for AI context — total counts, genres, year range.
     */
    public function catalogOverview(): array
    {
        $total = Movie::count();
        $genres = \App\Models\Genre::orderBy('name')->pluck('name')->toArray();
        $yearRange = Movie::selectRaw('MIN(YEAR(release_date)) as min_year, MAX(YEAR(release_date)) as max_year')
            ->whereNotNull('release_date')
            ->first();

        return [
            'total_films' => $total,
            'genres' => $genres,
            'year_range' => $yearRange ? "{$yearRange->min_year}–{$yearRange->max_year}" : null,
        ];
    }

    /**
     * Compact slug→title map of ENTIRE catalog. Sent to AI as authoritative whitelist.
     * Cached 10 min.
     *
     * @return array<string, string>  slug => title
     */
    public function fullCatalogIndex(): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'kb:full-catalog-index',
            600,
            fn () => Movie::pluck('title', 'slug')->toArray()
        );
    }

    /**
     * Check if a slug exists in the catalog.
     */
    public function slugExists(string $slug): bool
    {
        return in_array($slug, array_keys($this->fullCatalogIndex()), true);
    }

    /**
     * Given a film title (possibly hallucinated by AI), find the closest real
     * match in the catalog. Returns Movie or null.
     */
    public function findClosestByTitle(string $title): ?Movie
    {
        $needle = mb_strtolower(trim($title));
        if (empty($needle)) return null;

        // 1. Exact title match (case-insensitive)
        $exact = Movie::whereRaw('LOWER(title) = ?', [$needle])
            ->orWhereRaw('LOWER(original_title) = ?', [$needle])
            ->first();
        if ($exact) return $exact;

        // 2. Slug match
        $bySlug = Movie::where('slug', \Illuminate\Support\Str::slug($title))->first();
        if ($bySlug) return $bySlug;

        // 3. LIKE fuzzy match
        $fuzzy = Movie::where('title', 'LIKE', "%{$needle}%")
            ->orWhere('original_title', 'LIKE', "%{$needle}%")
            ->orderByDesc('popularity')
            ->first();
        if ($fuzzy) return $fuzzy;

        // 4. Token overlap (last resort, low precision)
        $tokens = preg_split('/\s+/', $needle);
        if (count($tokens) >= 2) {
            $allMovies = Movie::all(['id', 'slug', 'title']);
            $best = null;
            $bestScore = 0;
            foreach ($allMovies as $m) {
                $titleLower = mb_strtolower($m->title);
                $score = 0;
                foreach ($tokens as $t) {
                    if (mb_strlen($t) >= 3 && str_contains($titleLower, $t)) $score++;
                }
                if ($score >= 2 && $score > $bestScore) {
                    $best = $m;
                    $bestScore = $score;
                }
            }
            if ($best) return $best;
        }

        return null;
    }

    /**
     * Format a movie as compact AI-friendly text (for prompt injection).
     */
    public function formatForAi(Movie $m): array
    {
        return [
            'title' => $m->title,
            'original_title' => $m->original_title !== $m->title ? $m->original_title : null,
            'year' => $m->release_date?->format('Y'),
            'rating' => $m->vote_average ? round((float) $m->vote_average, 1) : null,
            'genres' => $m->genres->pluck('name')->toArray(),
            'cast' => $m->castMembers->take(5)->map(fn ($c) => [
                'name' => $c->name,
                'as' => $c->pivot->character ?? null,
            ])->toArray(),
            'overview' => Str::limit($m->overview ?? '', 240),
            'url' => '/movie/' . $m->slug,
            'popularity' => round((float) $m->popularity, 0),
        ];
    }

    /**
     * Extract keywords from user query (strip stopwords + tokenize).
     */
    protected function extractKeywords(string $query): array
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($query));
        $tokens = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);

        $kw = array_filter($tokens, fn ($t) => mb_strlen($t) >= 3 && !in_array($t, $this->stopWords, true));

        return array_values(array_unique($kw));
    }
}
