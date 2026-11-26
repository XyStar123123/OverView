<?php

namespace App\Domain\Runtime;

use App\Models\Execution;

/**
 * Runtime adapter boundary (ARCHITECTURE.md 5.2, 5.5 / PRD 6.8).
 *
 * A real runtime (OpenHands, LangGraph, …) implements this contract and is
 * registered via runtimes.adapter; communication would move to internal REST
 * + events. The prototype binds MockRunner (deterministic, in-process).
 */
interface RunnerContract
{
    /**
     * Execute the 10-step mock sequence for a queued execution (§5.3):
     * task_received → agent_initialized → recovery → memory_retrieval →
     * context_assembled → llm_request/llm_response → artifact.submit →
     * memory.propose → task_status_updated → execution_completed.
     *
     * @param  string|null  $memorySearchQuery  optional user-provided query appended to the task title
     */
    public function run(Execution $execution, ?string $memorySearchQuery = null): void;
}
