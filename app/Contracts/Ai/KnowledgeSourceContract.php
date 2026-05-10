<?php

namespace App\Contracts\Ai;

/**
 * Contract for any RAG knowledge source (films, articles, docs, etc).
 * Future: swap implementation from keyword-based to pgvector embeddings.
 */
interface KnowledgeSourceContract
{
    /**
     * Find items relevant to a free-text query.
     * Returns Eloquent Collection of model instances with optional `_relevance` score.
     */
    public function searchRelevant(string $query, int $limit = 5): \Illuminate\Support\Collection;

    /**
     * Get compact catalog overview (counts, categories, etc) for AI context.
     */
    public function catalogOverview(): array;
}
