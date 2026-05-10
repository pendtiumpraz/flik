<?php

namespace App\Contracts\Ai;

/**
 * Contract for web search service. Easy to swap providers (Wiki/DDG/Tavily/Brave).
 */
interface WebSearchContract
{
    /**
     * Search the web. Returns: [['title', 'snippet', 'url'], ...]
     */
    public function search(string $query, int $maxResults = 5): array;
}
