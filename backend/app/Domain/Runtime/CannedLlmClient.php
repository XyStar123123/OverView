<?php

namespace App\Domain\Runtime;

/**
 * Deterministic LLM stand-in (ARCHITECTURE.md 2.3 / 5.3 step 6).
 *
 * Produces canned text derived from the prompt — no real model, no network
 * call. Output tokens are estimated as ceil(strlen/4). A real gateway
 * adapter implements {@see LlmClient} and swaps the binding (5.5).
 */
class CannedLlmClient implements LlmClient
{
    public function chat(array $messages, string $model): LlmResult
    {
        $taskLabel = '';
        $memoryCount = 0;

        foreach ($messages as $message) {
            $content = (string) $message['content'];

            if (str_starts_with($content, 'TASK:')) {
                $taskLabel = substr($content, 5);
            }

            if (str_contains($content, '[memory]')) {
                $memoryCount++;
            }
        }

        $text = "[mock-llm] Task \"{$taskLabel}\": analyzed project context and {$memoryCount} retrieved memories.\n"
            . "Steps performed: planned implementation, wrote the deliverable, ran self-checks. Deliverable: task-report.md.";

        return new LlmResult($text, (int) ceil(strlen($text) / 4));
    }
}
