<?php

namespace App\Domain\Runtime;

use App\Domain\Memory\MemoryService;
use App\Enums\AgentStatus;
use App\Enums\ExecutionEventType;
use App\Enums\ExecutionStatus;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\ExecutionEvent;
use App\Models\Memory;
use App\Models\Task;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic mock agent loop (ARCHITECTURE.md 5.3, binding 10-step
 * sequence). Runs in-process under QUEUE_CONNECTION=sync; completes
 * before the HTTP response returns.
 *
 * Steps: 1 task_received → 2 agent_initialized → 3 recovery (working
 * memory, MAC-09) → 4 memory_retrieval (KeywordSearcher +
 * MemoryScopeGuard + context budget, MAC-02/03) → 5 context_assembled →
 * 6 llm_request/llm_response (CannedLlmClient) → 7 artifact.submit →
 * 8 memory.propose (working task memory + shared-scope proposal,
 * MAC-01/06) → 9 task_status_updated (in_review + task_review approval)
 * → 10 execution_completed (usage + audit, AC-10).
 */
class MockRunner implements RunnerContract
{
    public function __construct(
        private readonly CannedLlmClient $llm,
        private readonly ContextAssembler $assembler,
        private readonly MemoryService $memory,
    ) {
    }

    public function run(Execution $execution, ?string $memorySearchQuery = null): void
    {
        try {
            $this->step($execution, $memorySearchQuery);
        } catch (\Throwable $e) {
            // RUN-010: state never lost — execution failed + reason, task
            // stays/returns in_progress, agent → error.
            $task = Task::find($execution->task_id);
            $agent = Agent::find($execution->agent_id);

            $execution->update([
                'status' => ExecutionStatus::Failed->value,
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            ExecutionEvent::logEvent($execution->id, ExecutionEventType::ExecutionFailed->value, [
                'reason' => $e->getMessage(),
            ]);

            if ($task !== null && ! in_array($task->status, [TaskStatus::Cancelled->value, TaskStatus::Done->value], true)) {
                $task->update(['status' => TaskStatus::InProgress->value]);
            }

            if ($agent !== null && $agent->status !== AgentStatus::Offline->value) {
                $agent->update(['status' => AgentStatus::Error->value]);
            }
        }
    }

    private function step(Execution $execution, ?string $memorySearchQuery): void
    {
        $task = Task::findOrFail($execution->task_id);
        $project = $task->project;
        $agent = Agent::findOrFail($execution->agent_id);
        $workspace = $task->workspace;
        $startedMs = now()->getTimestampMs();

        $usage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'context_tokens' => 0,
            'tool_invocations' => 0,
            'estimated_cost' => 0.0,
            'duration_ms' => 0,
        ];

        // 1. task_received: the queued execution already carries this event
        //    (appended by ExecutionService); the runner starts from here.
        ExecutionEvent::logEvent($execution->id, ExecutionEventType::TaskReceived->value, [
            'taskId' => $task->id,
            'projectId' => $project->id,
            'agentId' => $agent->id,
        ]);

        // 2. agent_initialized: derived agent status + execution running.
        $agent->update(['status' => AgentStatus::Working->value]);
        $execution->update([
            'status' => ExecutionStatus::Running->value,
            'started_at' => now(),
        ]);
        ExecutionEvent::logEvent($execution->id, ExecutionEventType::AgentInitialized->value, [
            'runtime' => $execution->runtime_id,
            'agent' => $agent->id,
            'status' => AgentStatus::Working->value,
        ]);

        $tools = $agent->tool_allowlist ?? [];
        // 3. Recovery (MAC-09): reload this task's active working memory.
        $workingMemory = Memory::query()
            ->where('scope', 'task')
            ->where('task_id', $task->id)
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        // 4. memory_retrieval (tool memory.search, MAC-02/03).
        $memories = [];
        $scores = [];
        $keptIds = [];
        $truncated = false;
        $retrievalConfig = $agent->memoryConfig();

        if ($retrievalConfig['enabled']
            && $retrievalConfig['retrieval']['enabled']
            && in_array('memory.search', $tools, true)) {
            $query = trim($task->title . ' ' . ($memorySearchQuery ?? ''));
            $result = $this->memory->retrieveForExecution(
                $agent,
                $task,
                $query,
                $retrievalConfig['retrieval']['max_memories'],
            );
            $memories = $result['memories'];
            $scores = $result['scores'];
            $keptIds = $result['keptIds'];
            $truncated = $result['truncated'];
            $usage['retrieved_memory_count'] = count($memories);
            $this->memory->recordRetrievals($execution->id, $keptIds, $scores);

            ExecutionEvent::logEvent($execution->id, ExecutionEventType::MemoryRetrieval->value, [
                'query' => $query,
                'retrieved' => array_map(
                    static fn (Memory $memory, int $i) => ['memoryId' => $memory->id, 'score' => $scores[$i] ?? 0.0],
                    $memories,
                    array_keys($memories),
                ),
                'budgetTokens' => $retrievalConfig['retrieval']['context_budget_tokens'],
                'truncated' => $truncated,
            ]);
        } else {
            // Tool unavailable (disabled or not allowlisted) → tool_rejected,
            // continue without memory (AC-05).
            ExecutionEvent::logEvent($execution->id, ExecutionEventType::ToolRejected->value, [
                'tool' => 'memory.search',
                'reason' => 'not in agent tool allowlist or memory disabled',
            ]);
        }

        // 5. context_assembled.
        $assembled = $this->assembler->assemble($task, $agent, $memories, $workingMemory);
        $usage['input_tokens'] = $assembled['promptTokens'];
        foreach ($memories as $memory) {
            $usage['context_tokens'] += (int) ceil(strlen($memory->title . $memory->content) / 4);
        }
        ExecutionEvent::logEvent($execution->id, ExecutionEventType::ContextAssembled->value, [
            'sections' => $assembled['sections'],
            'promptTokens' => $assembled['promptTokens'],
        ]);

        // 6. llm_request / llm_response (deterministic canned client, no
        //    network — ARCHITECTURE.md 2.3).
        ExecutionEvent::logEvent($execution->id, ExecutionEventType::LlmRequest->value, [
            'model' => $execution->model,
            'promptTokens' => $assembled['promptTokens'],
        ]);
        $llmResult = $this->llm->chat($assembled['messages'], $execution->model);
        $usage['output_tokens'] = $llmResult->outputTokens;
        ExecutionEvent::logEvent($execution->id, ExecutionEventType::LlmResponse->value, [
            'model' => $execution->model,
            'outputTokens' => $llmResult->outputTokens,
            'text' => $llmResult->text,
        ]);

        // 7. tool_requested → tool_executed (artifact.submit): write to
        //    local disk under storage/app/overview/.
        $slug = (string) str($task->title)->slug();

        if (in_array('artifact.submit', $tools, true)) {
            $path = \App\Domain\Orchestration\ExecutionService::artifactPath($execution, $slug, 'task-report.md');
            $content = "# Task Report: {$task->title}\n\n"
                . "Project: {$project->name}\nAgent: {$agent->name}\nModel: {$execution->model} (source: {$execution->model_resolution['source']})\n\n"
                . "## Result\n\n"
                . $llmResult->text . "\n\n"
                . "## Retrieved Memory Context\n\n"
                . ($memories === []
                    ? '(none)'
                    : implode("\n", array_map(
                        static fn (Memory $memory) => "- {$memory->title}: {$memory->content}",
                        $memories,
                    ))) . "\n";
            Storage::disk('overview')->put($path, $content);

            $artifact = Artifact::create([
                'workspace_id' => $task->workspace_id,
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'execution_id' => $execution->id,
                'agent_id' => $agent->id,
                'name' => 'task-report.md',
                'artifact_type' => 'document',
                'file_path' => $path,
                'content_type' => 'text/markdown',
                'size_bytes' => strlen($content),
                'version' => 1,
                'status' => 'submitted',
            ]);
            $usage['tool_invocations']++;

            ExecutionEvent::logEvent($execution->id, ExecutionEventType::ToolRequested->value, ['tool' => 'artifact.submit']);
            ExecutionEvent::logEvent($execution->id, ExecutionEventType::ToolExecuted->value, ['tool' => 'artifact.submit']);
            ExecutionEvent::logEvent($execution->id, ExecutionEventType::ArtifactSubmitted->value, [
                'artifactId' => $artifact->id,
                'name' => $artifact->name,
            ]);
        } else {
            ExecutionEvent::logEvent($execution->id, ExecutionEventType::ToolRejected->value, [
                'tool' => 'artifact.submit',
                'reason' => 'not in agent tool allowlist',
            ]);
        }

        // 8. memory.propose (MAC-01/06).
        //    a) working task-scope memory (progress + decisions, active);
        //    b) shared-scope proposal → pending_review + memory_review
        //       approval (gated on the memory.propose tool).
        $working = $this->memory->propose(
            $workspace,
            $agent,
            'task',
            'working',
            "Working context: {$task->title}",
            "Execution {$execution->id} on \"{$task->title}\": " . mb_substr($llmResult->text, 0, 240) . " Deliverable submitted; task moved to review.",
            $execution->id,
            $task->project_id,
            $task->id,
            0.7,
            'medium',
        );
        ExecutionEvent::logEvent($execution->id, ExecutionEventType::MemoryStored->value, [
            'memoryId' => $working->id,
            'scope' => 'task',
            'status' => $working->status,
        ]);

        if (in_array('memory.propose', $tools, true)) {
            $usage['tool_invocations']++;
            ExecutionEvent::logEvent($execution->id, ExecutionEventType::ToolRequested->value, ['tool' => 'memory.propose']);
            ExecutionEvent::logEvent($execution->id, ExecutionEventType::ToolExecuted->value, ['tool' => 'memory.propose']);

            $proposed = $this->memory->propose(
                $workspace,
                $agent,
                'project',
                'semantic',
                "API decision: versioned routes for \"{$task->title}\"",
                "From task \"{$task->title}\" (execution {$execution->id}): keep versioned routes and a stable contract; deliverable in task-report.md. API v2 decisions: versioned routes.",
                $execution->id,
                $task->project_id,
                null,
                0.6,
                'medium',
                "Proposal from execution {$execution->id} on task \"{$task->title}\"",
            );
            ExecutionEvent::logEvent($execution->id, ExecutionEventType::MemoryStored->value, [
                'memoryId' => $proposed->id,
                'scope' => 'project',
                'status' => $proposed->status,
            ]);
        } else {
            ExecutionEvent::logEvent($execution->id, ExecutionEventType::ToolRejected->value, [
                'tool' => 'memory.propose',
                'reason' => 'not in agent tool allowlist',
            ]);
        }

        // 9. task_status_updated → in_review + task_review approval (AC-07).
        $fromStatus = $task->status;
        $task->update(['status' => TaskStatus::InReview->value]);
        ExecutionEvent::logEvent($execution->id, ExecutionEventType::TaskStatusUpdated->value, [
            'taskId' => $task->id,
            'from' => $fromStatus,
            'to' => TaskStatus::InReview->value,
        ]);

        $approval = Approval::create([
            'workspace_id' => $task->workspace_id,
            'request_type' => 'task_review',
            'task_id' => $task->id,
            'execution_id' => $execution->id,
            'status' => 'pending',
            'requested_by_type' => 'agent',
            'requested_by_id' => $agent->id,
            'created_at' => now(),
        ]);
        ExecutionEvent::logEvent($execution->id, ExecutionEventType::ApprovalRequested->value, [
            'approvalId' => $approval->id,
            'requestType' => 'task_review',
        ]);

        // 10. execution_completed: final usage, finished_at, agent back to
        //     available, audit row (AC-10).
        $usage['duration_ms'] = now()->getTimestampMs() - $startedMs;

        $execution->update([
            'status' => ExecutionStatus::Completed->value,
            'finished_at' => now(),
            'usage' => $usage,
        ]);
        $agent->update(['status' => AgentStatus::Available->value]);

        ExecutionEvent::logEvent($execution->id, ExecutionEventType::ExecutionCompleted->value, [
            'usage' => $usage,
        ]);

        AuditLog::record(
            $task->workspace_id,
            'agent',
            $agent->id,
            'execution',
            $execution->id,
            'execution.completed',
            ['status' => ExecutionStatus::Running->value],
            ['status' => ExecutionStatus::Completed->value, 'usage' => $usage],
            $execution->id,
        );
    }
}
