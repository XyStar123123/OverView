<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiException;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

abstract class ApiV1Controller
{
    /**
     * Envelope a payload with the §4.1 shape ({ data: … }).
     */
    protected function data(mixed $payload, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $payload], $status);
    }

    /** @return Response<void> */
    protected function noContent(): Response
    {
        return response()->noContent();
    }

    /**
     * The session user's active workspace (single-workspace prototype,
     * §2.5/§4.1: no workspace-select header). 404 when none.
     */
    protected function workspaceFor(Request $request): Workspace
    {
        $workspace = $request->user()->workspaces()->first();

        if ($workspace === null) {
            throw ApiException::notFound('No workspace for this user.');
        }

        return $workspace;
    }

    protected function roleFor(Request $request): string
    {
        return WorkspaceMember::where('workspace_id', $this->workspaceFor($request)->id)
            ->where('user_id', $request->user()->id)
            ->value('role') ?? 'member';
    }

    /**
     * Viewer is read-only for shared mutations (§1.2: minimum enforcement).
     */
    protected function requireMutation(Request $request): void
    {
        if ($this->roleFor($request) === 'viewer') {
            throw ApiException::forbidden('Viewer role is read-only.');
        }
    }

    /**
     * @param  array<int, string>  $allowed  resource fields the route accepts
     */
    protected function fields(Request $request, array $allowed): array
    {
        return $request->only($allowed);
    }

    // ---- §4.1 resource → JSON transformers (camelCase, computed fields) ----

    public function transformUser(object $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }

    public function transformWorkspace(Workspace $w): array
    {
        return [
            'id' => $w->id,
            'name' => $w->name,
            'slug' => $w->slug,
            'description' => $w->description,
            'defaultGatewayId' => $w->default_gateway_id,
            'defaultModel' => $w->default_model,
            'defaultRuntimeId' => $w->default_runtime_id,
            'memoryPolicy' => $w->memoryPolicy(),
        ];
    }

    public function transformProject(\App\Models\Project $p): array
    {
        return [
            'id' => $p->id,
            'workspaceId' => $p->workspace_id,
            'slug' => $p->slug,
            'name' => $p->name,
            'description' => $p->description,
            'objectives' => $p->objectives ?? [],
            'status' => $p->status,
            'priority' => $p->priority,
            'startDate' => $p->start_date?->toIso8601String(),
            'dueDate' => $p->due_date?->toIso8601String(),
            'ownerUserId' => $p->owner_user_id,
            'agentCount' => (int) $p->agents()->count(),
            'taskCount' => (int) $p->tasks()->count(),
        ];
    }

    public function modelOverrideFor(\App\Models\Task $task): array
    {
        $resolvedSource = 'workspace';

        if (! $task->override_agent_model) {
            $agent = $task->assignee_type === 'agent' ? $task->assignee : null;

            if ($agent !== null && $agent->default_model !== null && $agent->gateway_id !== null) {
                $resolvedSource = 'agent';
            }
        } else {
            $resolvedSource = 'task';
        }

        return [
            'enabled' => (bool) $task->override_agent_model,
            'gatewayId' => $task->override_gateway_id,
            'model' => $task->override_model,
            'resolvedSource' => $resolvedSource,
        ];
    }

    public function transformTask(\App\Models\Task $t): array
    {
        $hasRunning = \App\Models\Execution::where('task_id', $t->id)
            ->whereIn('status', ['queued', 'running', 'waiting'])
            ->exists();

        return [
            'id' => $t->id,
            'projectId' => $t->project_id,
            'parentTaskId' => $t->parent_id,
            'title' => $t->title,
            'description' => $t->description,
            'type' => $t->type,
            'customType' => $t->type_custom,
            'status' => $t->status,
            'priority' => $t->priority,
            'assignee' => $t->assignee_id !== null
                ? ['type' => $t->assignee_type, 'id' => $t->assignee_id]
                : null,
            'createdBy' => ['type' => $t->creator_type, 'id' => (int) $t->creator_id],
            'dueDate' => $t->due_date?->toIso8601String(),
            'labels' => $t->labels ?? [],
            'modelOverride' => $this->modelOverrideFor($t),
            'hasRunningExecution' => $hasRunning,
            'createdAt' => $t->created_at?->toIso8601String(),
            'updatedAt' => $t->updated_at?->toIso8601String(),
        ];
    }

    public function transformTaskComment(\App\Models\TaskComment $c): array
    {
        return [
            'id' => $c->id,
            'taskId' => $c->task_id,
            'actor' => ['type' => $c->actor_type, 'id' => $c->actor_id],
            'body' => $c->body,
            'executionId' => $c->execution_id,
            'createdAt' => $c->created_at?->toIso8601String(),
        ];
    }

    public function transformAgent(\App\Models\Agent $a): array
    {
        $cfg = $a->memoryConfig();
        $budget = $a->execution_budget;

        return [
            'id' => $a->id,
            'name' => $a->name,
            'slug' => $a->slug,
            'avatar' => $a->avatar,
            'description' => $a->description,
            'role' => $a->role,
            'instructions' => $a->instructions,
            'version' => $a->version,
            'status' => $a->status,
            'gatewayId' => $a->gateway_id,
            'defaultModel' => $a->default_model,
            'runtimeId' => $a->runtime_id,
            'toolAllowlist' => $a->tool_allowlist ?? [],
            'memory' => [
                'enabled' => $cfg['enabled'],
                'allowedScopes' => $cfg['allowed_scopes'],
                'retrieval' => [
                    'enabled' => $cfg['retrieval']['enabled'],
                    'maxMemories' => $cfg['retrieval']['max_memories'],
                    'contextBudgetTokens' => $cfg['retrieval']['context_budget_tokens'],
                ],
            ],
            'executionBudget' => $budget !== null ? ['maxExecutionsPerTask' => $budget['max_executions_per_task'] ?? null] : null,
            'concurrencyLimit' => $a->concurrency_limit,
            'createdAt' => $a->created_at?->toIso8601String(),
            'updatedAt' => $a->updated_at?->toIso8601String(),
        ];
    }

    public function transformGateway(\App\Models\Gateway $g): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'type' => $g->type,
            'baseUrl' => $g->base_url,
            'apiKeyRef' => $g->api_key_ref,
            'apiFormat' => $g->api_format,
            'status' => $g->status,
            'models' => $g->models ?? [],
            'lastCheckedAt' => $g->last_checked_at?->toIso8601String(),
        ];
    }

    public function transformRuntime(\App\Models\Runtime $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'adapter' => $r->adapter,
            'status' => $r->status,
            'capabilities' => $r->capabilities ?? [],
        ];
    }

    public function transformExecution(\App\Models\Execution $e): array
    {
        $usage = $e->usage ?? [];

        return [
            'id' => $e->id,
            'taskId' => $e->task_id,
            'projectId' => $e->project_id,
            'agentId' => $e->agent_id,
            'runtimeId' => $e->runtime_id,
            'gatewayId' => $e->gateway_id,
            'model' => $e->model,
            'modelResolution' => $e->model_resolution ?? null,
            'status' => $e->status,
            'startedAt' => $e->started_at?->toIso8601String(),
            'finishedAt' => $e->finished_at?->toIso8601String(),
            'usage' => [
                'inputTokens' => (int) ($usage['input_tokens'] ?? 0),
                'outputTokens' => (int) ($usage['output_tokens'] ?? 0),
                'contextTokens' => (int) ($usage['context_tokens'] ?? 0),
                'toolInvocations' => (int) ($usage['tool_invocations'] ?? 0),
                'retrievedMemoryCount' => (int) ($usage['retrieved_memory_count'] ?? 0),
                'estimatedCost' => (float) ($usage['estimated_cost'] ?? 0.0),
                'durationMs' => (int) ($usage['duration_ms'] ?? 0),
            ],
            'failureReason' => $e->failure_reason,
            'createdAt' => $e->created_at?->toIso8601String(),
        ];
    }

    public function transformExecutionEvent(\App\Models\ExecutionEvent $ev): array
    {
        return [
            'id' => $ev->id,
            'executionId' => $ev->execution_id,
            'sequence' => $ev->sequence,
            'type' => $ev->type,
            'payload' => $ev->payload ?? [],
            'createdAt' => $ev->created_at?->toIso8601String(),
        ];
    }

    public function transformArtifact(\App\Models\Artifact $a): array
    {
        return [
            'id' => $a->id,
            'taskId' => $a->task_id,
            'executionId' => $a->execution_id,
            'agentId' => $a->agent_id,
            'name' => $a->name,
            'artifactType' => $a->artifact_type,
            'contentType' => $a->content_type,
            'sizeBytes' => $a->size_bytes,
            'version' => $a->version,
            'status' => $a->status,
            'reviewedBy' => $a->reviewed_by !== null ? (int) $a->reviewed_by : null,
            'reviewNote' => $a->review_note,
            'createdAt' => $a->created_at?->toIso8601String(),
        ];
    }

    public function transformMemory(\App\Models\Memory $m): array
    {
        $source = $m->source ?? [];

        return [
            'id' => $m->id,
            'scope' => $m->scope,
            'type' => $m->type,
            'projectId' => $m->project_id,
            'agentId' => $m->agent_id,
            'taskId' => $m->task_id,
            'executionId' => $m->execution_id,
            'title' => $m->title,
            'content' => $m->content,
            'status' => $m->status,
            'version' => $m->version,
            'confidence' => (float) $m->confidence,
            'trust' => $m->trust,
            'source' => [
                'type' => $source['type'] ?? 'system',
                'refId' => $source['ref_id'] !== null ? (int) $source['ref_id'] : null,
                'actorType' => $source['actor_type'] ?? 'system',
                'actorId' => (int) ($source['actor_id'] ?? 0),
                'summary' => $source['summary'] ?? '',
            ],
            'createdBy' => ['type' => $m->created_by_type, 'id' => (int) ($m->created_by_id ?? 0)],
            'approvedBy' => $m->approved_by !== null ? (int) $m->approved_by : null,
            'approvedAt' => $m->approved_at?->toIso8601String(),
            'expiresAt' => $m->expires_at?->toIso8601String(),
            'lastAccessedAt' => $m->last_accessed_at?->toIso8601String(),
            'createdAt' => $m->created_at?->toIso8601String(),
            'updatedAt' => $m->updated_at?->toIso8601String(),
        ];
    }

    public function transformMemoryVersion(\App\Models\MemoryVersion $v): array
    {
        return [
            'id' => $v->id,
            'memoryId' => $v->memory_id,
            'version' => $v->version,
            'content' => $v->content,
            'changedBy' => ['type' => $v->changed_by_type, 'id' => (int) $v->changed_by_id],
            'reason' => $v->reason,
            'createdAt' => $v->created_at?->toIso8601String(),
        ];
    }

    public function transformApproval(\App\Models\Approval $a): array
    {
        return [
            'id' => $a->id,
            'requestType' => $a->request_type,
            'taskId' => $a->task_id,
            'memoryId' => $a->memory_id,
            'executionId' => $a->execution_id,
            'status' => $a->status,
            'requestedBy' => ['type' => $a->requested_by_type, 'id' => (int) $a->requested_by_id],
            'decisionUserId' => $a->decision_user_id !== null ? (int) $a->decision_user_id : null,
            'decisionReason' => $a->decision_reason,
            'decidedAt' => $a->decided_at?->toIso8601String(),
            'expiresAt' => $a->expires_at?->toIso8601String(),
            'createdAt' => $a->created_at?->toIso8601String(),
        ];
    }
}
