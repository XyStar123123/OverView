<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orchestration\ExecutionService;
use App\Enums\ExecutionStatus;
use App\Http\ApiException;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\ExecutionEvent;
use App\Models\Memory;
use App\Models\MemoryRetrieval;
use Illuminate\Http\Request;

class ExecutionsController extends ApiV1Controller
{
    /** GET /executions — query: agentId? projectId? status? (desc, §4.2). */
    public function index(Request $request)
    {
        $workspace = $this->workspaceFor($request);
        $agentId = $request->query('agentId');
        $projectId = $request->query('projectId');
        $status = $request->query('status');

        $executions = Execution::where('workspace_id', $workspace->id)
            ->when($agentId !== null && $agentId !== '', fn ($q) => $q->where('agent_id', $agentId))
            ->when($projectId !== null && $projectId !== '', fn ($q) => $q->where('project_id', $projectId))
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->get();

        return $this->data($executions->map(fn (Execution $e) => $this->transformExecution($e))->all());
    }

    /** GET /executions/{id}. */
    public function show(Request $request, int $id)
    {
        return $this->data($this->transformExecution($this->executionFor($request, $id)));
    }

    /** GET /executions/{id}/events — query: afterSequence? (asc sequence). */
    public function events(Request $request, int $id)
    {
        $execution = $this->executionFor($request, $id);
        $after = $request->query('afterSequence');

        $events = ExecutionEvent::where('execution_id', $execution->id)
            ->when($after !== null && $after !== '', fn ($q) => $q->where('sequence', '>', $after))
            ->orderBy('sequence')
            ->get();

        return $this->data($events->map(fn (ExecutionEvent $e) => $this->transformExecutionEvent($e))->all());
    }

    /** GET /executions/{id}/artifacts. */
    public function artifacts(Request $request, int $id)
    {
        $execution = $this->executionFor($request, $id);

        $artifacts = Artifact::where('execution_id', $execution->id)->orderBy('id')->get();

        return $this->data($artifacts->map(fn (Artifact $a) => $this->transformArtifact($a))->all());
    }

    /**
     * GET /executions/{id}/memories — §4.2:
     * retrieved = memory_retrievals of this execution (rank + score);
     * proposed = memories proposed by the agent during this execution.
     */
    public function memories(Request $request, int $id)
    {
        $execution = $this->executionFor($request, $id);

        $retrievalRows = MemoryRetrieval::where('execution_id', $execution->id)->orderBy('rank')->get();
        $memoryIds = $retrievalRows->pluck('memory_id')->all();
        $byId = $memoryIds !== [] ? Memory::find($memoryIds)->keyBy('id') : collect();

        $retrieved = $retrievalRows
            ->filter(fn (MemoryRetrieval $row) => $byId->has($row->memory_id))
            ->map(fn (MemoryRetrieval $row) => $this->transformMemory($byId->get($row->memory_id)) + [
                'rank' => $row->rank,
                'score' => (float) $row->score,
            ])
            ->values()
            ->all();

        $proposed = Memory::where('execution_id', $execution->id)
            ->where('created_by_type', 'agent')
            ->orderBy('id')
            ->get()
            ->map(fn (Memory $m) => $this->transformMemory($m))
            ->all();

        return $this->data([
            'retrieved' => $retrieved,
            'proposed' => $proposed,
        ]);
    }

    /** GET /executions/{id}/model-resolution — §4.2. */
    public function modelResolution(Request $request, int $id)
    {
        $execution = $this->executionFor($request, $id);

        return $this->data($execution->model_resolution ?? null);
    }

    /**
     * POST /executions/{id}/cancel — §4.2: mock supports cancel only while
     * queued; running mock executions cannot be force-stopped → 422.
     */
    public function cancel(Request $request, int $id)
    {
        $execution = $this->executionFor($request, $id);
        $this->requireMutation($request);

        if (in_array($execution->status, [ExecutionStatus::Running->value, ExecutionStatus::Waiting->value], true)) {
            throw ApiException::invalidState(
                'Mock runner does not support cancel; running executions cannot be force-stopped.',
                ['status' => $execution->status],
            );
        }

        $execution = app(ExecutionService::class)->cancel($execution);

        return $this->data($this->transformExecution($execution));
    }

    private function executionFor(Request $request, int $id): Execution
    {
        $execution = Execution::where('workspace_id', $this->workspaceFor($request)->id)->find($id);

        if ($execution === null) {
            throw ApiException::notFound('Execution not found.');
        }

        return $execution;
    }
}
