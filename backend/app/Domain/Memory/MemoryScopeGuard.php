<?php

namespace App\Domain\Memory;

use App\Models\Agent;
use App\Models\Memory;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scope isolation enforcement (ARCHITECTURE.md 3.10, MAC-03).
 *
 * - workspace scope: same workspace only; only when "workspace" is in the
 *   agent's allowed_memory_scopes.
 * - project scope: only the task's project, and only if the agent is bound
 *   to that project via project_agents. Two unrelated projects never leak
 *   into each other.
 * - agent scope: the owning agent only (PRD 6.16.3).
 * - task scope: the task's own working memories (execution owner).
 *
 * Only status=active memories are returned (MAC-06); memory content never
 * adds permission (MEM-050) — allowlists come from the agent row.
 */
class MemoryScopeGuard
{
    /**
     * Memories the agent may retrieve while executing this task.
     *
     * @return Builder<Memory>
     */
    public function visibleToAgentForTask(Agent $agent, Task $task): Builder
    {
        $scopes = $agent->allowed_memory_scopes ?? [];

        return Memory::query()
            ->where('workspace_id', $task->workspace_id)
            ->where('status', 'active')
            ->where(function (Builder $q) use ($agent, $task, $scopes): void {
                if (in_array('task', $scopes, true)) {
                    $q->orWhere(fn (Builder $w) => $w
                        ->where('scope', 'task')
                        ->where('task_id', $task->id));
                }

                if (in_array('workspace', $scopes, true)) {
                    $q->orWhere(fn (Builder $w) => $w->where('scope', 'workspace'));
                }

                if (in_array('agent', $scopes, true)) {
                    $q->orWhere(fn (Builder $w) => $w
                        ->where('scope', 'agent')
                        ->where('agent_id', $agent->id));
                }

                if (in_array('project', $scopes, true)) {
                    // MAC-03: project memory is visible only when the agent
                    // is bound to the task's project via project_agents.
                    $bound = $agent->projects()
                        ->where('projects.id', $task->project_id)
                        ->exists();

                    if ($bound) {
                        $q->orWhere(fn (Builder $w) => $w
                            ->where('scope', 'project')
                            ->where('project_id', $task->project_id));
                    }
                }
            });
    }
}
