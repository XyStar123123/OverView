<?php

namespace App\Domain\Runtime;

/**
 * Result of one LLM call. Prototype usage tokens are deterministic
 * estimates (ceil(strlen/4), ARCHITECTURE.md 5.3 step 6).
 */
final class LlmResult
{
    public function __construct(
        public readonly string $text,
        public readonly int $outputTokens,
    ) {
    }
}
