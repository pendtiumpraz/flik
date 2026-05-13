<?php

namespace App\Services\Ai;

use App\Exceptions\SsrfException;
use App\Services\Security\SafeHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Free web search — Wikipedia (reliable for film/people entities) + DuckDuckGo fallback.
 * No API keys needed. Used as "tool" by DeepSeek's agentic function calling.
 *
 * All outbound HTTP is routed through {@see SafeHttp} → {@see \App\Services\Security\SsrfGuard}
 * so user-influenced search queries can't be coerced into private-network probes.
 */
class WebSearchService
{
    public function __construct(protected SafeHttp $http)
    {
    }

    /**
     * Search the web. Returns array of ['title', 'snippet', 'url'].
     * Cached 1 hour per query.
     *
     * @return array<int, array{title:string, snippet:string, url:string}>
     */
    public function search(string $query, int $maxResults = 5): array
    {
        $query = trim($query);
        if (empty($query)) return [];

        $cacheKey = 'websearch:' . md5($query);

        return Cache::remember($cacheKey, now()->addHour(), function () use ($query, $maxResults) {
            $results = [];

            // 1. Wikipedia — paling reliable untuk film, aktor, sutradara
            try {
                $wiki = $this->wikipedia($query, max(3, intval($maxResults / 2)));
                $results = array_merge($results, $wiki);
            } catch (\Throwable $e) {
                Log::warning('Wikipedia search failed', ['query' => $query, 'err' => $e->getMessage()]);
            }

            // 2. DuckDuckGo Instant Answer — kalau wiki belum cukup
            if (count($results) < $maxResults) {
                try {
                    $ddg = $this->duckduckgo($query, $maxResults - count($results));
                    $results = array_merge($results, $ddg);
                } catch (\Throwable $e) {
                    Log::warning('DuckDuckGo search failed', ['query' => $query, 'err' => $e->getMessage()]);
                }
            }

            return array_slice($results, 0, $maxResults);
        });
    }

    /**
     * Wikipedia search via REST API. Free, no key, reliable for entities.
     */
    protected function wikipedia(string $query, int $maxResults = 3): array
    {
        $headers = ['User-Agent' => 'FLiK Assistant/1.0 (https://flik.id)'];

        // Step 1: search for matching pages
        try {
            $searchResp = $this->http->get(
                'https://en.wikipedia.org/w/api.php',
                [
                    'action'   => 'query',
                    'list'     => 'search',
                    'srsearch' => $query,
                    'srlimit'  => $maxResults,
                    'format'   => 'json',
                ],
                ['timeout' => 6, 'headers' => $headers],
            );
        } catch (SsrfException $e) {
            Log::warning('Wikipedia: SSRF guard blocked search request', ['err' => $e->getMessage()]);
            return [];
        }

        if (!$searchResp->successful()) return [];

        $hits = $searchResp->json('query.search', []);
        if (empty($hits)) return [];

        // Step 2: get extracts (intro paragraphs) for top hits
        $titles = collect($hits)->pluck('title')->take($maxResults)->implode('|');

        try {
            $extractResp = $this->http->get(
                'https://en.wikipedia.org/w/api.php',
                [
                    'action'      => 'query',
                    'prop'        => 'extracts|info',
                    'exintro'     => 1,
                    'explaintext' => 1,
                    'inprop'      => 'url',
                    'titles'      => $titles,
                    'format'      => 'json',
                ],
                ['timeout' => 6, 'headers' => $headers],
            );
        } catch (SsrfException $e) {
            Log::warning('Wikipedia: SSRF guard blocked extract request', ['err' => $e->getMessage()]);
            return [];
        }

        if (!$extractResp->successful()) return [];

        $pages = $extractResp->json('query.pages', []);
        $results = [];
        foreach ($pages as $page) {
            if (empty($page['extract'])) continue;
            $results[] = [
                'title' => $page['title'] ?? '',
                'snippet' => mb_substr(trim($page['extract']), 0, 500),
                'url' => $page['fullurl'] ?? '',
            ];
        }

        return $results;
    }

    /**
     * DuckDuckGo Instant Answer API (no key).
     */
    protected function duckduckgo(string $query, int $maxResults = 3): array
    {
        try {
            $resp = $this->http->get(
                'https://api.duckduckgo.com/',
                [
                    'q'             => $query,
                    'format'        => 'json',
                    'no_html'       => 1,
                    'skip_disambig' => 1,
                    't'             => 'flik-ai',
                ],
                ['timeout' => 6, 'headers' => ['User-Agent' => 'FLiK Assistant/1.0']],
            );
        } catch (SsrfException $e) {
            Log::warning('DuckDuckGo: SSRF guard blocked request', ['err' => $e->getMessage()]);
            return [];
        }

        if (!$resp->successful()) return [];

        $data = $resp->json();
        $results = [];

        if (!empty($data['AbstractText'])) {
            $results[] = [
                'title' => $data['Heading'] ?? $query,
                'snippet' => mb_substr($data['AbstractText'], 0, 500),
                'url' => $data['AbstractURL'] ?? '',
            ];
        }

        foreach (($data['RelatedTopics'] ?? []) as $topic) {
            if (count($results) >= $maxResults) break;
            if (empty($topic['Text'])) continue;
            $results[] = [
                'title' => mb_substr(strtok($topic['Text'], '-'), 0, 100),
                'snippet' => mb_substr($topic['Text'], 0, 400),
                'url' => $topic['FirstURL'] ?? '',
            ];
        }

        return array_slice($results, 0, $maxResults);
    }
}
