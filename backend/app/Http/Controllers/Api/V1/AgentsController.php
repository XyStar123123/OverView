<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiException;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\Gateway;
use App\Models\Memory;
use App\Models\Runtime;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class AgentsController extends ApiV1Controller
{
    /** GET /agents — query: status?. */
    public function index(Request $request)
    {
        $workspace = $this->workspaceFor($request);
        $status = $request->query('status');

        $agents = Agent::where('workspace_id', $workspace->id)
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('id')
            ->get();

        return $this->data($agents->map(fn (Agent $a) => $this->transformAgent($a))->all());
    }

    /** POST /agents — 201, initial status draft (§4.2). */
    public function store(Request $request)
    {
        $workspace = $this->workspaceFor($request);
        $this->requireMutation($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'sometimes|string|max:255',
            'role' => 'required|string',
            'description' => 'sometimes|nullable|string',
            'instructions' => 'sometimes|nullable|string',
            'avatar' => 'sometimes|nullable|string',
            'gatewayId' => 'required|integer',
            'defaultModel' => 'required|string',
            'runtimeId' => 'required|integer',
            'toolAllowlist' => 'sometimes|nullable|array',
            'toolAllowlist.*' => 'string',
            'memory' => 'sometimes|array',
            'memory.enabled' => 'boolean',
            'memory.allowedScopes' => 'array',
            'memory.allowedScopes.*' => 'in:workspace,project,agent,task',
            'memory.retrieval' => 'array',
            'memory.retrieval.enabled' => 'boolean',
            'memory.retrieval.maxMemories' => 'integer|min:0',
            'memory.retrieval.contextBudgetTokens' => 'integer|min:0',
            'executionBudget' => 'sometimes|nullable|array',
            'executionBudget.maxExecutionsPerTask' => 'integer|min:1',
            'concurrencyLimit' => 'sometimes|integer|min:1',
        ]);

        $gateway = Gateway::where('workspace_id', $workspace->id)->find($validated['gatewayId']);
        $runtime = Runtime::where('workspace_id', $workspace->id)->find($validated['runtimeId']);

        if ($gateway === null) {
            throw ApiException::validation('Unknown gateway.', ['gatewayId' => $validated['gatewayId']]);
        }

        if ($runtime === null) {
            throw ApiException::validation('Unknown runtime.', ['runtimeId' => $validated['runtimeId']]);
        }

        $memory = $validated['memory'] ?? [];

        $agent = Agent::create([
            'workspace_id' => $workspace->id,
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'role' => $validated['role'],
            'instructions' => $validated['instructions'] ?? null,
            'version' => 1,
            'status' => 'draft',
            'gateway_id' => $gateway->id,
            'default_model' => $validated['defaultModel'],
            'runtime_id' => $runtime->id,
            'tool_allowlist' => $validated['toolAllowlist'] ?? [],
            'memory_enabled' => (bool) ($memory['enabled'] ?? true),
            'allowed_memory_scopes' => $memory['allowedScopes'] ?? [],
            'memory_retrieval' => $memory['retrieval'] ?? ['enabled' => true, 'max_memories' => 5, 'context_budget_tokens' => 2000],
            'execution_budget' => $validated['executionBudget'] ?? null,
            'concurrency_limit' => $validated['concurrencyLimit'] ?? 1,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::record($workspace->id, 'user', $request->user()->id, 'agent', $agent->id, 'agent.created', null, ['name' => $agent->name, 'status' => 'draft']);

        return $this->data($this->transformAgent($agent), 201);
    }

    public function show(Request $request, int $id)
    {
        return $this->data($this->transformAgent($this->agentFor($request, $id)));
    }

    /** PATCH /agents/{id} — subset fields. */
    public function update(Request $request, int $id)
    {
        $agent = $this->agentFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'role' => 'sometimes|string',
            'instructions' => 'sometimes|nullable|string',
            'avatar' => 'sometimes|nullable|string',
            'status' => 'sometimes|string|in:draft,available,working,blocked,waiting_for_approval,offline,disabled,error',
            'gatewayId' => 'sometimes|integer',
            'defaultModel' => 'sometimes|string',
            'runtimeId' => 'sometimes|integer',
            'toolAllowlist' => 'sometimes|nullable|array',
            'toolAllowlist.*' => 'string',
            'memory' => 'sometimes|array',
            'memory.enabled' => 'boolean',
            'memory.allowedScopes' => 'array',
            'memory.allowedScopes.*' => 'in:workspace,project,agent,task',
            'memory.retrieval' => 'array',
            'memory.retrieval.enabled' => 'boolean',
            'memory.retrieval.maxMemories' => 'integer|min:0',
            'memory.retrieval.contextBudgetTokens' => 'integer|min:0',
            'executionBudget' => 'sometimes|nullable|array',
            'executionBudget.maxExecutionsPerTask' => 'integer|min:1',
            'concurrencyLimit' => 'sometimes|integer|min:1',
        ]);

        $before = $agent->only(['name', 'role', 'status']);
        $memory = $validated['memory'] ?? [];
        $retrieval = $memory['retrieval'] ?? ($agent->memory_retrieval ?? ['enabled' => true, 'max_memories' => 5, 'context_budget_tokens' => 2000]);

        $agent->update([
            'name' => $validated['name'] ?? $agent->name,
            'slug' => $validated['slug'] ?? $agent->slug,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $agent->description,
            'role' => $validated['role'] ?? $agent->role,
            'instructions' => array_key_exists('instructions', $validated) ? $validated['instructions'] : $agent->instructions,
            'status' => $validated['status'] ?? $agent->status,
            'gateway_id' => $validated['gatewayId'] ?? $agent->gateway_id,
            'default_model' => $validated['defaultModel'] ?? $agent->default_model,
            'runtime_id' => $validated['runtimeId'] ?? $agent->runtime_id,
            'tool_allowlist' => $validated['toolAllowlist'] ?? $agent->tool_allowlist,
            'memory_enabled' => array_key_exists('enabled', $memory) ? (bool) $memory['enabled'] : $agent->memory_enabled,
            'allowed_memory_scopes' => $memory['allowedScopes'] ?? $agent->allowed_memory_scopes,
            'memory_retrieval' => [
                'enabled' => (bool) ($retrieval['enabled'] ?? true),
                'max_memories' => (int) ($retrieval['maxMemories'] ?? $retrieval['max_memories'] ?? 5),
                'context_budget_tokens' => (int) ($retrieval['contextBudgetTokens'] ?? $retrieval['context_budget_tokens'] ?? 2000),
            ],
            'execution_budget' => array_key_exists('executionBudget', $validated) ? ($validated['executionBudget'] ?: null) : $agent->execution_budget,
            'concurrency_limit' => $validated['concurrencyLimit'] ?? $agent->concurrency_limit,
        ]);

        AuditLog::record($agent->workspace_id, 'user', $request->user()->id, 'agent', $agent->id, 'agent.updated', $before, $agent->only(['name', 'role', 'status']));

        return $this->data($this->transformAgent($agent->fresh()));
    }

    /** DELETE /agents/{id} — 204; agent memories stay (AGT-026, MAC-04). */
    public function destroy(Request $request, int $id): Response
    {
        $agent = $this->agentFor($request, $id);
        $this->requireMutation($request);

        AuditLog::record($agent->workspace_id, 'user', $request->user()->id, 'agent', $agent->id, 'agent.deleted', ['name' => $agent->name, 'status' => $agent->status], null);

        $agent->delete();

        return $this->noContent();
    }

    /** POST /agents/{id}/activate — status → available. */
    public function activate(Request $request, int $id)
    {
        $agent = $this->agentFor($request, $id);
        $this->requireMutation($request);

        $this->assertStatusChangeAllowed($agent, 'available');

        $agent->update(['status' => 'available']);

        AuditLog::record($agent->workspace_id, 'user', $request->user()->id, 'agent', $agent->id, 'agent.activated', ['status' => 'draft'], ['status' => 'available']);

        return $this->data($this->transformAgent($agent->fresh()));
    }

    /** POST /agents/{id}/deactivate — status → offline. */
    public function deactivate(Request $request, int $id)
    {
        $agent = $this->agentFor($request, $id);
        $this->requireMutation($request);

        if ($agent->status === 'offline') {
            throw ApiException::invalidState('Agent is already offline.', ['status' => $agent->status]);
        }

        $agent->update(['status' => 'offline']);

        AuditLog::record($agent->workspace_id, 'user', $request->user()->id, 'agent', $agent->id, 'agent.deactivated', ['status' => $agent->status], ['status' => 'offline']);

        return $this->data($this->transformAgent($agent->fresh()));
    }

    /** GET /agents/{id}/executions — query: status?. */
    public function executions(Request $request, int $id)
    {
        $agent = $this->agentFor($request, $id);
        $status = $request->query('status');

        $executions = Execution::where('agent_id', $agent->id)
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->get();

        return $this->data($executions->map(fn (Execution $e) => $this->transformExecution($e))->all());
    }

    /** GET /agents/{id}/memory — scope=agent & agentId. */
    public function memory(Request $request, int $id)
    {
        $agent = $this->agentFor($request, $id);

        $memories = Memory::where('workspace_id', $agent->workspace_id)
            ->where('scope', 'agent')
            ->where('agent_id', $agent->id)
            ->orderBy('id')
            ->get();

        return $this->data($memories->map(fn (Memory $m) => $this->transformMemory($m))->all());
    }

    /** PATCH /agents/{id}/memory-config — { enabled?; allowedScopes?; retrieval? }. */
    public function memoryConfig(Request $request, int $id)
    {
        $agent = $this->agentFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'allowedScopes' => 'sometimes|array',
            'allowedScopes.*' => 'in:workspace,project,agent,task',
            'retrieval' => 'sometimes|array',
            'retrieval.enabled' => 'boolean',
            'retrieval.maxMemories' => 'integer|min:0',
            'retrieval.contextBudgetTokens' => 'integer|min:0',
        ]);

        $retrieval = $validated['retrieval'] ?? ($agent->memory_retrieval ?? ['enabled' => true, 'max_memories' => 5, 'context_budget_tokens' => 2000]);

        $agent->update([
            'memory_enabled' => array_key_exists('enabled', $validated) ? (bool) $validated['enabled'] : $agent->memory_enabled,
            'allowed_memory_scopes' => $validated['allowedScopes'] ?? $agent->allowed_memory_scopes,
            'memory_retrieval' => [
                'enabled' => (bool) ($retrieval['enabled'] ?? true),
                'max_memories' => (int) ($retrieval['maxMemories'] ?? $retrieval['max_memories'] ?? 5),
                'context_budget_tokens' => (int) ($retrieval['contextBudgetTokens'] ?? $retrieval['context_budget_tokens'] ?? 2000),
            ],
        ]);

        $agent->refresh();

        AuditLog::record($agent->workspace_id, 'user', $request->user()->id, 'agent', $agent->id, 'agent.memory_config_updated', null, $agent->memoryConfig());

        return $this->data($agent->memoryConfig());
    }

    private function agentFor(Request $request, int $id): Agent
    {
        $agent = Agent::where('workspace_id', $this->workspaceFor($request)->id)->find($id);

        if ($agent === null) {
            throw ApiException::notFound('Agent not found.');
        }

        return $agent;
    }

    private function assertStatusChangeAllowed(Agent $agent, string $to): void
    {
        if ($agent->status === 'disabled') {
            throw ApiException::invalidState('A disabled agent cannot be activated.', ['status' => $agent->status]);
        }

        if ($agent->status === $to) {
            throw ApiException::invalidState('Agent is already ' . $to . '.', ['status' => $agent->status]);
        }
    }
}
