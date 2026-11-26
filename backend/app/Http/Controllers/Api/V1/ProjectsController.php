<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiException;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Memory;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ProjectsController extends ApiV1Controller
{
    /** GET /projects — §4.2, query: status?. */
    public function index(Request $request)
    {
        $workspace = $this->workspaceFor($request);
        $status = $request->query('status');

        $projects = Project::where('workspace_id', $workspace->id)
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('id')
            ->get();

        return $this->data($projects->map(fn (Project $p) => $this->transformProject($p))->all());
    }

    /** POST /projects — 201 { data: Project }; owner becomes project member. */
    public function store(Request $request)
    {
        $workspace = $this->workspaceFor($request);
        $this->requireMutation($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'objectives' => 'sometimes|nullable|array',
            'objectives.*' => 'string',
            'status' => 'sometimes|string|in:planning,active,paused,completed,archived',
            'priority' => 'sometimes|string|in:low,medium,high',
            'agentIds' => 'sometimes|array',
            'agentIds.*' => 'integer',
        ]);

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'slug' => $validated['slug'] ?? Str::slug($validated['name']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'objectives' => $validated['objectives'] ?? null,
            'status' => $validated['status'] ?? 'planning',
            'priority' => $validated['priority'] ?? 'medium',
            'owner_user_id' => $request->user()->id,
        ]);

        ProjectMember::create(['project_id' => $project->id, 'user_id' => $request->user()->id]);

        foreach ($validated['agentIds'] ?? [] as $agentId) {
            $agent = Agent::where('workspace_id', $workspace->id)->find($agentId);

            if ($agent === null) {
                throw ApiException::validation('Unknown agent.', ['agentId' => $agentId]);
            }

            $agent->projects()->syncWithoutDetaching([$agent->id]);
        }

        AuditLog::record($workspace->id, 'user', $request->user()->id, 'project', $project->id, 'project.created', null, ['name' => $project->name, 'status' => $project->status]);

        return $this->data($this->transformProject($project->fresh()), 201);
    }

    public function show(Request $request, int $id)
    {
        return $this->data($this->transformProject($this->projectFor($request, $id)));
    }

    /** PATCH /projects/{id} — subset of project fields. */
    public function update(Request $request, int $id)
    {
        $project = $this->projectFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'objectives' => 'sometimes|nullable|array',
            'objectives.*' => 'string',
            'status' => 'sometimes|string|in:planning,active,paused,completed,archived',
            'priority' => 'sometimes|string|in:low,medium,high',
            'startDate' => 'sometimes|nullable|date',
            'dueDate' => 'sometimes|nullable|date',
        ]);

        $before = $project->only(['name', 'status', 'priority']);
        $project->update([
            'name' => $validated['name'] ?? $project->name,
            'description' => $validated['description'] ?? $project->description,
            'objectives' => $validated['objectives'] ?? $project->objectives,
            'status' => $validated['status'] ?? $project->status,
            'priority' => $validated['priority'] ?? $project->priority,
            'start_date' => array_key_exists('startDate', $validated) ? $validated['startDate'] : $project->start_date,
            'due_date' => array_key_exists('dueDate', $validated) ? $validated['dueDate'] : $project->due_date,
        ]);

        AuditLog::record($project->workspace_id, 'user', $request->user()->id, 'project', $project->id, 'project.updated', $before, $project->only(['name', 'status', 'priority']));

        return $this->data($this->transformProject($project->fresh()));
    }

    /** DELETE /projects/{id} — 204 only when the project is archived (§4.2). */
    public function destroy(Request $request, int $id): Response
    {
        $project = $this->projectFor($request, $id);
        $this->requireMutation($request);

        if ($project->status !== 'archived') {
            throw ApiException::invalidState('Only archived projects can be deleted.', ['status' => $project->status]);
        }

        AuditLog::record($project->workspace_id, 'user', $request->user()->id, 'project', $project->id, 'project.deleted', $project->only(['name', 'status']), null);

        return $this->noContent();
    }

    /** GET /projects/{id}/agents — bound agents via project_agents (MAC-03). */
    public function agents(Request $request, int $id)
    {
        $project = $this->projectFor($request, $id);
        $agents = $project->agents()->orderBy('id')->get();

        return $this->data($agents->map(fn (Agent $a) => $this->transformAgent($a))->all());
    }

    /** POST /projects/{id}/agents — { agentId } → 201 { data: { projectId; agentId } }. */
    public function assignAgent(Request $request, int $id)
    {
        $project = $this->projectFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate(['agentId' => 'required|integer']);

        $agent = Agent::where('workspace_id', $project->workspace_id)->find($validated['agentId']);

        if ($agent === null) {
            throw ApiException::validation('Agent not found in this workspace.', ['agentId' => $validated['agentId']]);
        }

        $agent->projects()->syncWithoutDetaching([$agent->id]);

        AuditLog::record($project->workspace_id, 'user', $request->user()->id, 'project', $project->id, 'project.agent_assigned', null, ['agentId' => $agent->id]);

        return $this->data(['projectId' => $project->id, 'agentId' => $agent->id], 201);
    }

    /** GET /projects/{id}/memory — scope=project memories, query: type? status?. */
    public function memory(Request $request, int $id)
    {
        $project = $this->projectFor($request, $id);
        $memories = $this->memoriesForProject($request, $project);

        return $this->data($memories->map(fn (Memory $m) => $this->transformMemory($m))->all());
    }

    /** GET /projects/{id}/tasks — query: status? type?. */
    public function tasks(Request $request, int $id)
    {
        $project = $this->projectFor($request, $id);
        $tasks = $this->tasksForProject($request, $project);

        return $this->data($tasks->map(fn (Task $t) => $this->transformTask($t))->all());
    }

    /** POST /projects/{id}/tasks — 201 { data: Task }. */
    public function createTask(Request $request, int $id)
    {
        $project = $this->projectFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'type' => 'sometimes|string|in:feature,bug,fix,research,documentation,maintenance,investigation,custom',
            'priority' => 'sometimes|string|in:low,medium,high',
            'assignee' => 'sometimes|nullable|array',
            'assignee.type' => 'in:user,agent',
            'assignee.id' => 'integer',
            'dueDate' => 'sometimes|nullable|date',
            'labels' => 'sometimes|nullable|array',
            'labels.*' => 'string',
            'overrideAgentModel' => 'sometimes|boolean',
            'overrideGatewayId' => 'sometimes|nullable|integer',
            'overrideModel' => 'sometimes|nullable|string',
        ]);

        $assignee = $validated['assignee'] ?? null;
        $assigneeType = null;
        $assigneeId = null;

        if ($assignee !== null) {
            $assigneeType = $assignee['type'] ?? 'user';
            $assigneeId = $assignee['id'] ?? null;

            if ($assigneeId !== null && $assigneeType === 'agent') {
                $agent = Agent::where('workspace_id', $project->workspace_id)->find($assigneeId);

                if ($agent === null) {
                    throw ApiException::validation('Assignee agent not found in this workspace.', ['assignee' => $assigneeId]);
                }
            } elseif ($assigneeId !== null) {
                $user = User::find($assigneeId);

                if ($user === null) {
                    throw ApiException::validation('Assignee user not found.', ['assignee' => $assigneeId]);
                }
            }
        }

        $task = Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'] ?? 'feature',
            'status' => 'todo',
            'priority' => $validated['priority'] ?? 'medium',
            'assignee_type' => $assigneeType,
            'assignee_id' => $assigneeId,
            'creator_type' => 'user',
            'creator_id' => $request->user()->id,
            'due_date' => $validated['dueDate'] ?? null,
            'labels' => $validated['labels'] ?? null,
            'override_agent_model' => (bool) ($validated['overrideAgentModel'] ?? false),
            'override_gateway_id' => $validated['overrideGatewayId'] ?? null,
            'override_model' => $validated['overrideModel'] ?? null,
        ]);

        AuditLog::record($project->workspace_id, 'user', $request->user()->id, 'task', $task->id, 'task.created', null, ['title' => $task->title, 'status' => $task->status], null);

        return $this->data($this->transformTask($task), 201);
    }

    private function projectFor(Request $request, int $id): Project
    {
        $project = Project::where('workspace_id', $this->workspaceFor($request)->id)->find($id);

        if ($project === null) {
            throw ApiException::notFound('Project not found.');
        }

        return $project;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Memory> */
    private function memoriesForProject(Request $request, Project $project)
    {
        $type = $request->query('type');
        $status = $request->query('status');

        return Memory::where('workspace_id', $project->workspace_id)
            ->where('scope', 'project')
            ->where('project_id', $project->id)
            ->when($type !== null && $type !== '', fn ($q) => $q->where('type', $type))
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Task> */
    private function tasksForProject(Request $request, Project $project)
    {
        $status = $request->query('status');
        $type = $request->query('type');

        return Task::where('project_id', $project->id)
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->when($type !== null && $type !== '', fn ($q) => $q->where('type', $type))
            ->orderBy('id')
            ->get();
    }
}
