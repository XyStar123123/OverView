<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orchestration\ExecutionService;
use App\Enums\ApprovalStatus;
use App\Http\ApiException;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\Memory;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TasksController extends ApiV1Controller
{
    /** GET /tasks/{id} — §4.2. */
    public function show(Request $request, int $id)
    {
        return $this->data($this->transformTask($this->taskFor($request, $id)));
    }

    /** PATCH /tasks/{id} — subset fields; model override via dedicated route. */
    public function update(Request $request, int $id)
    {
        $task = $this->taskFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'status' => 'sometimes|string|in:todo,in_progress,pending,blocked,in_review,done,cancelled',
            'priority' => 'sometimes|string|in:low,medium,high',
            'dueDate' => 'sometimes|nullable|date',
            'labels' => 'sometimes|nullable|array',
            'labels.*' => 'string',
            'assignee' => 'sometimes|nullable|array',
            'assignee.type' => 'in:user,agent',
            'assignee.id' => 'integer',
        ]);

        $before = $task->only(['title', 'status', 'priority']);

        if (array_key_exists('status', $validated) && $validated['status'] !== $task->status) {
            if (! $task->canTransitionTo($validated['status'])) {
                throw ApiException::invalidState(
                    "Task status cannot transition from {$task->status} to {$validated['status']}.",
                    ['from' => $task->status, 'to' => $validated['status']],
                );
            }

            $task->update(['status' => $validated['status']]);
        }

        $assigneeType = $task->assignee_type;
        $assigneeId = $task->assignee_id;

        if (array_key_exists('assignee', $validated)) {
            $assignee = $validated['assignee'];

            if ($assignee === null) {
                $assigneeType = null;
                $assigneeId = null;
            } else {
                $assigneeType = $assignee['type'] ?? 'user';
                $assigneeId = $assignee['id'] ?? null;
            }
        }

        $task->update([
            'title' => $validated['title'] ?? $task->title,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $task->description,
            'priority' => $validated['priority'] ?? $task->priority,
            'due_date' => array_key_exists('dueDate', $validated) ? $validated['dueDate'] : $task->due_date,
            'labels' => $validated['labels'] ?? $task->labels,
            'assignee_type' => $assigneeType,
            'assignee_id' => $assigneeId,
        ]);

        AuditLog::record($task->workspace_id, 'user', $request->user()->id, 'task', $task->id, 'task.updated', $before, $task->only(['title', 'status', 'priority']));

        return $this->data($this->transformTask($task->fresh()));
    }

    /** POST /tasks/{id}/assign — 200 { data: Task } · 409 conflict when execution in flight. */
    public function assign(Request $request, int $id)
    {
        $task = $this->taskFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate([
            'assignee' => 'required|array',
            'assignee.type' => 'required|in:user,agent',
            'assignee.id' => 'required|integer',
        ]);

        $type = $validated['assignee']['type'];
        $assignee = $type === 'agent'
            ? Agent::where('workspace_id', $task->workspace_id)->find($validated['assignee']['id'])
            : User::find($validated['assignee']['id']);

        if ($assignee === null) {
            throw ApiException::validation("Assignee {$type} not found in this workspace.", ['assignee' => $validated['assignee']['id']]);
        }

        if ($type === 'agent' && $assignee->status !== 'available') {
            throw ApiException::conflict('Assigned agent is not available.', ['agent_id' => $assignee->id, 'agent_status' => $assignee->status]);
        }

        if (Execution::where('task_id', $task->id)->whereIn('status', ['queued', 'running'])->exists()) {
            throw ApiException::conflict('Task already has an active execution; reassign it after the execution settles.', ['task_id' => $task->id]);
        }

        $task->update(['assignee_type' => $type, 'assignee_id' => $assignee->id]);

        AuditLog::record($task->workspace_id, 'user', $request->user()->id, 'task', $task->id, 'task.updated', null, ['assignee_type' => $type, 'assignee_id' => $assignee->id]);

        return $this->data($this->transformTask($task->fresh()));
    }

    /** POST /tasks/{id}/comments — 201 { data: TaskComment }. */
    public function addComment(Request $request, int $id)
    {
        $task = $this->taskFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate(['body' => 'required|string']);

        $comment = TaskComment::create([
            'task_id' => $task->id,
            'actor_type' => 'user',
            'actor_id' => $request->user()->id,
            'body' => $validated['body'],
            'created_at' => now(),
        ]);

        return $this->data($this->transformTaskComment($comment), 201);
    }

    /** GET /tasks/{id}/comments — ascending. */
    public function comments(Request $request, int $id)
    {
        $task = $this->taskFor($request, $id);

        $comments = TaskComment::where('task_id', $task->id)->orderBy('id')->get();

        return $this->data($comments->map(fn (TaskComment $c) => $this->transformTaskComment($c))->all());
    }

    /** POST /tasks/{id}/execute — 202 { data: Execution, message } (§4.2, §5.1). */
    public function execute(Request $request, int $id)
    {
        $task = $this->taskFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate(['memorySearchQuery' => 'sometimes|nullable|string']);

        $execution = app(ExecutionService::class)->start($task, $validated['memorySearchQuery'] ?? null, $request->user()->id);

        return response()->json(['data' => $this->transformExecution($execution), 'message' => 'execution started'], 202);
    }

    /**
     * POST /tasks/{id}/review — resolves (or raises) the task_review approval
     * and propagates effects: approve → task done; reject → task in_progress
     * + comment (§4.2, AC-07/APR-004).
     */
    public function review(Request $request, int $id)
    {
        $task = $this->taskFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'sometimes|nullable|string',
        ]);

        if ($task->status !== 'in_review') {
            throw ApiException::invalidState('Task is not in review.', ['status' => $task->status]);
        }

        $userId = $request->user()->id;
        $execution = Execution::where('task_id', $task->id)->latest('id')->first();

        $approval = Approval::where('workspace_id', $task->workspace_id)
            ->where('request_type', 'task_review')
            ->where('task_id', $task->id)
            ->where('status', ApprovalStatus::Pending->value)
            ->first() ?? Approval::create([
                'workspace_id' => $task->workspace_id,
                'request_type' => 'task_review',
                'task_id' => $task->id,
                'execution_id' => $execution?->id,
                'status' => 'pending',
                'requested_by_type' => 'agent',
                'requested_by_id' => $task->assignee_id ?? 0,
                'created_at' => now(),
            ]);

        if ($validated['decision'] === 'approve') {
            $task->update(['status' => 'done']);
            $approval->update([
                'status' => 'approved',
                'decision_user_id' => $userId,
                'decision_reason' => $validated['reason'] ?? null,
                'decided_at' => now(),
            ]);
            AuditLog::record($task->workspace_id, 'user', $userId, 'task', $task->id, 'task.status_updated', ['status' => 'in_review'], ['status' => 'done'], $execution?->id);
        } else {
            $task->update(['status' => 'in_progress']);
            TaskComment::create([
                'task_id' => $task->id,
                'actor_type' => 'user',
                'actor_id' => $userId,
                'body' => 'Review rejected' . ($validated['reason'] !== null && $validated['reason'] !== '' ? ': ' . $validated['reason'] : '.'),
                'execution_id' => $execution?->id,
                'created_at' => now(),
            ]);
            $approval->update([
                'status' => 'rejected',
                'decision_user_id' => $userId,
                'decision_reason' => $validated['reason'] ?? null,
                'decided_at' => now(),
            ]);
            AuditLog::record($task->workspace_id, 'user', $userId, 'task', $task->id, 'task.status_updated', ['status' => 'in_review'], ['status' => 'in_progress'], $execution?->id);
        }

        return $this->data([
            'approval' => $this->transformApproval($approval->fresh()),
            'task' => $this->transformTask($task->fresh()),
        ]);
    }

    /** PATCH /tasks/{id}/model-override — 422 when enabled without gateway+model. */
    public function modelOverride(Request $request, int $id)
    {
        $task = $this->taskFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'gatewayId' => 'sometimes|nullable|integer',
            'model' => 'sometimes|nullable|string',
        ]);

        $enabled = $validated['enabled'] ?? $task->override_agent_model;
        $gatewayId = array_key_exists('gatewayId', $validated) ? $validated['gatewayId'] : $task->override_gateway_id;
        $model = array_key_exists('model', $validated) ? $validated['model'] : $task->override_model;

        if ($enabled && ($gatewayId === null || $model === null)) {
            throw ApiException::validation('A model override requires both gatewayId and model.');
        }

        $task->update([
            'override_agent_model' => $enabled,
            'override_gateway_id' => $gatewayId,
            'override_model' => $model,
        ]);

        AuditLog::record($task->workspace_id, 'user', $request->user()->id, 'task', $task->id, 'task.model_override_updated', null, ['enabled' => $enabled, 'gatewayId' => $gatewayId, 'model' => $model]);

        return $this->data($this->modelOverrideFor($task->fresh()));
    }

    /** GET /tasks/{id}/artifacts. */
    public function artifacts(Request $request, int $id)
    {
        $task = $this->taskFor($request, $id);

        $artifacts = Artifact::where('task_id', $task->id)->orderBy('id')->get();

        return $this->data($artifacts->map(fn (Artifact $a) => $this->transformArtifact($a))->all());
    }

    /**
     * GET /tasks/{id}/memory — { working, proposed } (§4.2):
     * working = active task-scope memories; proposed = memories proposed from
     * this task's executions (execution provenance).
     */
    public function memory(Request $request, int $id)
    {
        $task = $this->taskFor($request, $id);

        $working = Memory::where('scope', 'task')
            ->where('task_id', $task->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        $executionIds = Execution::where('task_id', $task->id)->pluck('id');
        $proposed = $executionIds->isNotEmpty()
            ? Memory::whereIn('execution_id', $executionIds)
                ->where('created_by_type', 'agent')
                ->orderBy('id')
                ->get()
            : collect();

        return $this->data([
            'working' => $working->map(fn (Memory $m) => $this->transformMemory($m))->all(),
            'proposed' => $proposed->map(fn (Memory $m) => $this->transformMemory($m))->all(),
        ]);
    }

    /** GET /tasks/{id}/executions — descending. */
    public function executions(Request $request, int $id)
    {
        $task = $this->taskFor($request, $id);

        $executions = Execution::where('task_id', $task->id)->orderByDesc('id')->get();

        return $this->data($executions->map(fn (Execution $e) => $this->transformExecution($e))->all());
    }

    private function taskFor(Request $request, int $id): Task
    {
        $task = Task::where('workspace_id', $this->workspaceFor($request)->id)->find($id);

        if ($task === null) {
            throw ApiException::notFound('Task not found.');
        }

        return $task;
    }
}
