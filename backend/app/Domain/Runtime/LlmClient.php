<?php

namespace App\Domain\Runtime;

/**
 * LLM adapter boundary (ARCHITECTURE.md 5.5, LLM-013/AC-11).
 *
 * The prototype binds this to {@see CannedLlmClient} (deterministic, no
 * network). A real gateway adapter (OmniRoute/LiteLLM/OpenAI-compatible)
 * replaces the binding only — the runner and controller layers never change.
 */
interface LlmClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages  prompt assembled by ContextAssembler
     */
    public function chat(array $messages, string $model): LlmResult;
}
