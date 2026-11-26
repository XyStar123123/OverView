<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiException;
use App\Models\Runtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RuntimesController extends ApiV1Controller
{
    /** GET /runtimes — §4.2. */
    public function index(Request $request)
    {
        $workspace = $this->workspaceFor($request);

        $runtimes = Runtime::where('workspace_id', $workspace->id)->orderBy('id')->get();

        return $this->data($runtimes->map(fn (Runtime $r) => $this->transformRuntime($r))->all());
    }

    /** POST /runtimes — 201 { data: Runtime }. */
    public function store(Request $request)
    {
        $workspace = $this->workspaceFor($request);
        $this->requireMutation($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'adapter' => 'required|string|in:mock,openhands,langgraph,custom',
            'capabilities' => 'sometimes|nullable|array',
            'capabilities.*' => 'string',
        ]);

        $runtime = Runtime::create([
            'workspace_id' => $workspace->id,
            'name' => $validated['name'],
            'adapter' => $validated['adapter'],
            'status' => 'unknown',
            'capabilities' => $validated['capabilities'] ?? [],
        ]);

        return $this->data($this->transformRuntime($runtime), 201);
    }

    /** POST /runtimes/{id}/health — mock health probe (§4.2). */
    public function health(Request $request, int $id): JsonResponse
    {
        $runtime = $this->runtimeFor($request, $id);

        $runtime->update(['status' => 'healthy']);

        return response()->json(['healthy' => true, 'status' => 'healthy']);
    }

    private function runtimeFor(Request $request, int $id): Runtime
    {
        $runtime = Runtime::where('workspace_id', $this->workspaceFor($request)->id)->find($id);

        if ($runtime === null) {
            throw ApiException::notFound('Runtime not found.');
        }

        return $runtime;
    }
}
