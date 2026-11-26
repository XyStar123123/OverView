<?php

namespace App\Domain\Memory;
use App\Models\Memory;
use App\Models\MemoryRetrieval;

/**
 * Deterministic keyword retrieval (ARCHITECTURE.md 5.4, binding):
 *
 *   score = 2 * tokenOverlap(query, title)
 *         + tokenOverlap(query, content)
 *         + 0.5 * recencyBoost        # 1.0 if < 7 days old, else 0
 *         + confidence * 0.2
 *
 * tokenOverlap = |tokens(q) ∩ tokens(x)| / |tokens(q)|, lowercased,
 * non-empty, length > 2. Filter: status=active (guaranteed by caller),
 * score > 0.05. Recency: created within 7 days, or seeded within the
 * deterministic seed window (same math — based on created_at vs $now).
 */
class KeywordSearcher implements MemorySearcher
{
    private const MIN_SCORE = 0.05;

    public function search(string $query, array $candidates, ?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        $queryTokens = $this->tokens($query);

        if ($queryTokens === []) {
            return [];
        }

        $ranked = [];

        foreach ($candidates as $memory) {
            $score = 2 * $this->overlap($queryTokens, $this->tokens($memory->title))
                + $this->overlap($queryTokens, $this->tokens((string) $memory->content))
                + ($this->createdWithinDays($memory, $now, 7) ? 0.5 : 0.0)
                + (float) $memory->confidence * 0.2;

            if ($score > self::MIN_SCORE) {
                $ranked[] = ['memory' => $memory, 'score' => round($score, 4)];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $ranked;
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $text = strtolower($text);
        preg_match_all('/[a-z0-9_]{3,}/', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @param  array<int, string>  $queryTokens
     * @param  array<int, string>  $sourceTokens
     */
    private function overlap(array $queryTokens, array $sourceTokens): float
    {
        if ($queryTokens === []) {
            return 0.0;
        }

        $hits = count(array_intersect($queryTokens, $sourceTokens));

        return $hits / count($queryTokens);
    }

    private function createdWithinDays(Memory $memory, \DateTimeInterface $now, int $days): bool
    {
        $createdAt = $memory->created_at;

        if ($createdAt === null) {
            return false;
        }

        return $createdAt >= $now->modify('-' . $days . ' days');
    }
}
