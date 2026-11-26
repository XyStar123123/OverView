<?php

namespace App\Domain\Memory;

use App\Models\Memory;

/**
 * Memory retrieval adapter boundary (ARCHITECTURE.md 5.4 / 5.5).
 *
 * The prototype binds {@see KeywordSearcher} (deterministic keyword +
 * recency + confidence scoring). A future VectorSearcher (pgvector)
 * replaces the binding without touching the memories schema.
 */
interface MemorySearcher
{
    /**
     * Score candidate memories against the query and return them ranked
     * (best first). The caller applies scope guards and budgets.
     *
     * @param  array<int, Memory>  $candidates
     * @return array<int, array{memory: Memory, score: float}>  ranked, desc
     */
    public function search(string $query, array $candidates, \DateTimeInterface $now = null): array;
}
