<?php

namespace App\Domain\Runtime;

use App\Models\Gateway;
use App\Models\Task;

/**
 * Model hierarchy resolution Task > Agent > Workspace (ARCHITECTURE.md 3.10,
 * LLM-019/AC-13). The result is persisted on executions.model_resolution;
 * a task override NEVER rewrites agents.gateway_id/default_model.
 */
class ModelResolver
{
    /**
     * @return array{gateway: string, model: string, source: 'task'|'agent'|'workspace', gateway_id: int|null, runtime_id: int|null}
     */
    public function resolve(Task $task): array
    {
        $agent = $task->assignee_type === 'agent' ? $task->assignee : null;
        $workspace = $task->project->workspace;

        // Level 1: task-level override (LLM-016).
        if ($task->override_agent_model && $task->override_model !== null) {
            $gatewayId = $task->override_gateway_id ?? $workspace->default_gateway_id;

            return [
                'gateway' => $gatewayId !== null ? $this->gatewayName($gatewayId) : 'mock-gateway',
                'model' => $task->override_model,
                'source' => 'task',
                'gateway_id' => $gatewayId,
                'runtime_id' => $agent?->runtime_id ?? $workspace->default_runtime_id,
            ];
        }

        // Level 2: agent defaults.
        if ($agent && $agent->default_model !== null && $agent->gateway_id !== null) {
            return [
                'gateway' => $this->gatewayName($agent->gateway_id),
                'model' => $agent->default_model,
                'source' => 'agent',
                'gateway_id' => $agent->gateway_id,
                'runtime_id' => $agent->runtime_id,
            ];
        }

        // Level 3: workspace defaults.
        return [
            'gateway' => $workspace->default_gateway_id !== null
                ? $this->gatewayName($workspace->default_gateway_id)
                : 'mock-gateway',
            'model' => $workspace->default_model ?? 'mock-llm-lite',
            'source' => 'workspace',
            'gateway_id' => $workspace->default_gateway_id,
            'runtime_id' => $agent?->runtime_id ?? $workspace->default_runtime_id,
        ];
    }

    private function gatewayName(int $gatewayId): string
    {
        return Gateway::find($gatewayId)?->name ?? 'mock-gateway';
    }
}
