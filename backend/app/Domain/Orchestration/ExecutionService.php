<?php

namespace App\Domain\Orchestration;

use App\Enums\AgentStatus;
use App\Enums\ExecutionStatus;
use App\Enums\TaskStatus;
use App\Domain\Runtime\ModelResolver;
use App\Http\ApiException;
use App\Models\Agent;
use App\Models\Execution;
use App\Models\ExecutionEvent;
use App\Models\Task;
use Illuminate\Support\Facades\Storage;

/**
 * Execution lifecycle (ARCHITECTURE.md 5.1, 5.3 step 10):
 * - create(): pre-condition checks + queued execution row + job dispatch.
 * - cancel(): mock supports cancel only while queued (§4.2).
 * - artifact storage path under storage/app/overview (filesystem "overview"
 *   disk = local disk root).
 */
class ExecutionService
{
    public function __construct(
        private readonly ModelResolver $resolver,
    ) {
    }

    /**
     * Create a queued execution for a task and dispatch the runner job
     * (sync queue → in-process, ARCHITECTURE.md 5.1).
     *
     * @throws ApiException 409 conflict / 422 invalid_state
     */
    public function start(Task $task, ?string $memorySearchQuery = null, ?int $requestedByUserId = null): Execution
    {
        // TASK-015: no queued/running execution for this task.
        $active = Execution::where('task_id', $task->id)
            ->whereIn('status', [ExecutionStatus::Queued->value, ExecutionStatus::Running->value])
            ->exists();

        if ($active) {
            throw ApiException::conflict('Task already has an active execution.', ['task_id' => $task->id]);
        }

        if (! in_array($task->status, [TaskStatus::Todo->value, TaskStatus::InProgress->value], true)) {
            throw ApiException::invalidState('Task status does not allow execution.', ['status' => $task->status]);
        }

        if ($task->assignee_type !== 'agent') {
            throw ApiException::invalidState('Task is not assigned to an agent.');
        }

        $agent = $task->assignee;

        if ($agent === null || $agent->status !== AgentStatus::Available->value) {
            throw ApiException::invalidState('Assigned agent is not available.', ['agent_status' => $agent?->status]);
        }

        // Budget check (agents.execution_budget.max_executions_per_task).
        $budget = $agent->execution_budget ?? [];
        $max = $budget['max_executions_per_task'] ?? null;

        if ($max !== null) {
            $used = Execution::where('task_id', $task->id)->where('agent_id', $agent->id)->count();

            if ($used >= $max) {
                throw ApiException::invalidState('Execution budget exhausted for this task.', ['budget' => $max]);
            }
        }

        $resolution = $this->resolver->resolve($task);

        $execution = Execution::create([
            'workspace_id' => $task->workspace_id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'agent_id' => $agent->id,
            'runtime_id' => $resolution['runtime_id'],
            'gateway_id' => $resolution['gateway_id'],
            'model' => $resolution['model'],
            'model_resolution' => [
                'gateway' => $resolution['gateway'],
                'model' => $resolution['model'],
                'source' => $resolution['source'],
            ],
            'status' => ExecutionStatus::Queued->value,
        ]);

        // TASK-008: task enters in_progress at execution start.
        if ($task->status === TaskStatus::Todo->value) {
            $task->update(['status' => TaskStatus::InProgress->value]);
        }

        ExecutionEvent::logEvent($execution->id, \App\Enums\ExecutionEventType::TaskReceived->value, [
            'taskId' => $task->id,
            'projectId' => $task->project_id,
            'agentId' => $agent->id,
        ]);

        \App\Domain\Orchestration\ExecuteAgentJob::dispatch($execution->id, $memorySearchQuery);

        return $execution->fresh();
    }

    /**
     * Mock runner cancel (§4.2): only a queued execution can be cancelled;
     * a running mock execution cannot be force-stopped → 422.
     */
    public function cancel(Execution $execution): Execution
    {
        if ($execution->status !== ExecutionStatus::Queued->value) {
            throw ApiException::invalidState('Mock runner supports cancel only while queued; running executions cannot be force-stopped.');
        }

        $execution->update(['status' => ExecutionStatus::Cancelled->value, 'finished_at' => now()]);
        ExecutionEvent::logEvent($execution->id, \App\Enums\ExecutionEventType::ExecutionCompleted->value, ['cancelled' => true]);

        return $execution->fresh();
    }

    public static function artifactPath(Execution $execution, string $slug, string $name): string
    {
        $path = $execution->id . '-' . $slug . '.md';

        Storage::disk('overview')->makeDirectory(dirname($path));

        return $path;
    }
}
