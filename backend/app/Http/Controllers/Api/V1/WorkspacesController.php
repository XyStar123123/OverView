<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiException;
use App\Models\AuditLog;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;

class WorkspacesController extends ApiV1Controller
{
    /** GET /workspaces — the session user's workspaces (§4.2). */
    public function index(Request $request)
    {
        $workspaces = $request->user()->workspaces()->get();

        return $this->data($workspaces->map(fn ($w) => $this->transformWorkspace($w))->all());
    }

    /** GET /workspaces/{id} — AC-09: only the user's own workspace. */
    public function show(Request $request, int $id)
    {
        $workspace = $this->workspaceFor($request);

        if ($workspace->id !== $id) {
            throw ApiException::notFound('Workspace not found.');
        }

        return $this->data($this->transformWorkspace($workspace));
    }

    /** PATCH /workspaces/{id} — §4.2 workspace subset. */
    public function update(Request $request, int $id)
    {
        $workspace = $this->workspaceFor($request);

        if ($workspace->id !== $id) {
            throw ApiException::notFound('Workspace not found.');
        }

        $this->requireMutation($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'defaultGatewayId' => ['sometimes', 'nullable', 'integer'],
            'defaultModel' => ['sometimes', 'nullable', 'string'],
            'defaultRuntimeId' => ['sometimes', 'nullable', 'integer'],
            'memoryPolicy' => ['sometimes', 'array'],
        ]);

        $before = $workspace->only(['name', 'default_gateway_id', 'default_model', 'default_runtime_id']);
        $workspace->update([
            'name' => $validated['name'] ?? $workspace->name,
            'description' => $validated['description'] ?? $workspace->description,
            'default_gateway_id' => array_key_exists('defaultGatewayId', $validated) ? $validated['defaultGatewayId'] : $workspace->default_gateway_id,
            'default_model' => array_key_exists('defaultModel', $validated) ? $validated['defaultModel'] : $workspace->default_model,
            'default_runtime_id' => array_key_exists('defaultRuntimeId', $validated) ? $validated['defaultRuntimeId'] : $workspace->default_runtime_id,
            'memory_policy' => $validated['memoryPolicy'] ?? $workspace->memory_policy,
        ]);

        AuditLog::record($workspace->id, 'user', $request->user()->id, 'workspace', $workspace->id, 'workspace.updated', $before, $workspace->fresh()->only(['name', 'default_gateway_id', 'default_model', 'default_runtime_id']));

        return $this->data($this->transformWorkspace($workspace->fresh()));
    }

    /** GET /workspaces/{id}/members — §4.2 { user: User; role: Role }[]. */
    public function members(Request $request, int $id)
    {
        $workspace = $this->workspaceFor($request);

        if ($workspace->id !== $id) {
            throw ApiException::notFound('Workspace not found.');
        }

        $members = WorkspaceMember::where('workspace_id', $workspace->id)->with('user')->orderBy('id')->get();

        return $this->data($members
            ->map(fn ($m) => [
                'user' => $m->user !== null ? $this->transformUser($m->user) : null,
                'role' => $m->role,
            ])
            ->all());
    }
}
