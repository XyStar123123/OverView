<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Memory\KeywordSearcher;
use App\Domain\Memory\MemoryService;
use App\Enums\MemoryStatus;
use App\Http\ApiException;
use App\Models\AuditLog;
use App\Models\Memory;
use App\Models\Task;
use App\Models\MemoryVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MemoriesController extends ApiV1Controller
{
    /**
     * GET /memories — query: scope? type? status? projectId? agentId? q? (§4.2).
     */
    public function index(Request $request)
    {
        $workspace = $this->workspaceFor($request);
        $q = $request->query('q');

        $memories = Memory::where('workspace_id', $workspace->id)
            ->when($request->query('scope'), fn ($m) => $m->where('scope', $request->query('scope')))
            ->when($request->query('type'), fn ($m) => $m->where('type', $request->query('type')))
            ->when($request->query('status'), fn ($m) => $m->where('status', $request->query('status')))
            ->when($request->query('projectId'), fn ($m) => $m->where('project_id', $request->query('projectId')))
            ->when($request->query('agentId'), fn ($m) => $m->where('agent_id', $request->query('agentId')))
            ->when($q, fn ($m) => $m->where(fn ($c) => $c
                ->where('title', 'like', '%' . $q . '%')
                ->orWhere('content', 'like', '%' . $q . '%')
            ))
            ->orderBy('id')
            ->get();

        return $this->data($memories->map(fn (Memory $m) => $this->transformMemory($m))->all());
    }

    /** POST /memories — user-created → active directly (§4.2). */
    public function store(Request $request)
    {
        $workspace = $this->workspaceFor($request);
        $this->requireMutation($request);

        $validated = $request->validate([
            'scope' => 'required|in:workspace,project,agent,task',
            'type' => 'required|in:working,episodic,semantic,procedural',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'projectId' => 'nullable|integer',
            'agentId' => 'nullable|integer',
            'taskId' => 'nullable|integer',
            'confidence' => 'nullable|numeric|min:0|max:1',
            'trust' => 'nullable|in:low,medium,high',
        ]);

        $memory = app(MemoryService::class)->storeByUser(
            $workspace,
            $request->user()->id,
            $validated['scope'],
            $validated['type'],
            $validated['title'],
            $validated['content'],
            $validated['projectId'] ?? null,
            $validated['agentId'] ?? null,
            $validated['taskId'] ?? null,
            (float) ($validated['confidence'] ?? 0.5),
            $validated['trust'] ?? 'medium',
        );

        return $this->data($this->transformMemory($memory), 201);
    }

    /**
     * POST /memories/search — keyword search mirror of tool memory.search
     * (§4.2, §5.4); limit defaults to 10.
     */
    public function search(Request $request)
    {
        $workspace = $this->workspaceFor($request);

        $validated = $request->validate([
            'query' => 'required|string',
            'scope' => 'sometimes|in:workspace,project,agent,task',
            'type' => 'sometimes|in:working,episodic,semantic,procedural',
            'status' => 'sometimes|in:candidate,pending_review,approved,active,superseded,archived,rejected,expired',
            'projectId' => 'sometimes|nullable|integer',
            'agentId' => 'sometimes|nullable|integer',
            'limit' => 'sometimes|nullable|integer|min:1|max:50',
        ]);

        $candidates = Memory::where('workspace_id', $workspace->id)
            ->where('status', $validated['status'] ?? MemoryStatus::Active->value)
            ->when($validated['scope'] ?? null, fn ($m, $v) => $m->where('scope', $v))
            ->when($validated['type'] ?? null, fn ($m, $v) => $m->where('type', $v))
            ->when($validated['projectId'] ?? null, fn ($m, $v) => $m->where('project_id', $v))
            ->when($validated['agentId'] ?? null, fn ($m, $v) => $m->where('agent_id', $v))
            ->get()
            ->all();

        $ranked = app(KeywordSearcher::class)->search($validated['query'], $candidates);
        $limit = (int) ($validated['limit'] ?? 10);
        $top = array_slice($ranked, 0, $limit);

        $results = [];
        foreach ($top as $i => $entry) {
            $results[] = $this->transformMemory($entry['memory']) + [
                'score' => $entry['score'],
                'rank' => $i + 1,
            ];
        }

        return $this->data([
            'results' => $results,
            'total' => count($ranked),
        ]);
    }

    /**
     * GET /memories/{id} — Memory + versions + retrievals (§4.2 / MemoryDetail).
     */
    public function show(Request $request, int $id)
    {
        $memory = $this->memoryFor($request, $id);

        $versions = MemoryVersion::where('memory_id', $memory->id)->orderBy('version')->get();
        $retrievals = $memory->retrievals()->orderBy('rank')->get();

        return $this->data([
            'memory' => $this->transformMemory($memory),
            'versions' => $versions->map(fn (MemoryVersion $v) => $this->transformMemoryVersion($v))->all(),
            'retrievals' => $retrievals->map(fn ($r) => [
                'executionId' => $r->execution_id,
                'at' => $r->retrieved_at?->toIso8601String(),
                'rank' => $r->rank,
            ])->all(),
        ]);
    }

    /**
     * PATCH /memories/{id} — subset; content/title writes a memory_versions
     * row (MAC-05) via MemoryService::update.
     */
    public function update(Request $request, int $id)
    {
        $memory = $this->memoryFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'confidence' => 'sometimes|nullable|numeric|min:0|max:1',
            'expiresAt' => 'sometimes|nullable|date',
        ]);

        $fields = [];
        if (array_key_exists('title', $validated)) {
            $fields['title'] = $validated['title'];
        }
        if (array_key_exists('content', $validated)) {
            $fields['content'] = $validated['content'];
        }
        if (array_key_exists('confidence', $validated)) {
            $fields['confidence'] = $validated['confidence'];
        }
        if (array_key_exists('expiresAt', $validated)) {
            $fields['expires_at'] = $validated['expiresAt'];
        }

        $memory = app(MemoryService::class)->update(
            $memory,
            $fields,
            'user',
            $request->user()->id,
        );

        return $this->data($this->transformMemory($memory));
    }

    /** DELETE /memories/{id} — 204; ownership/retention check + audit (MAC-08). */
    public function destroy(Request $request, int $id): Response
    {
        $memory = $this->memoryFor($request, $id);
        $this->requireMutation($request);

        app(MemoryService::class)->delete($memory, 'user', $request->user()->id);

        return $this->noContent();
    }

    /** POST /memories/{id}/approve — status → active; linked approval → approved. */
    public function approve(Request $request, int $id)
    {
        $memory = $this->memoryFor($request, $id);
        $this->requireMutation($request);

        if ($memory->status !== MemoryStatus::PendingReview->value) {
            throw ApiException::invalidState('Only pending-review memories can be approved.', ['status' => $memory->status]);
        }

        $approval = $memory->approvals()
            ->where('request_type', 'memory_review')
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();

        if ($approval === null) {
            throw ApiException::invalidState('No pending memory_review approval found for this memory.');
        }

        $approval = app(MemoryService::class)->approve($approval, $request->user()->id);

        return $this->data([
            'memory' => $this->transformMemory($memory->fresh()),
            'approval' => $this->transformApproval($approval),
        ]);
    }

    /** POST /memories/{id}/reject — { reason? }; status → rejected. */
    public function reject(Request $request, int $id)
    {
        $memory = $this->memoryFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate(['reason' => 'sometimes|nullable|string']);

        if ($memory->status !== MemoryStatus::PendingReview->value) {
            throw ApiException::invalidState('Only pending-review memories can be rejected.', ['status' => $memory->status]);
        }

        $approval = $memory->approvals()
            ->where('request_type', 'memory_review')
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();

        if ($approval === null) {
            throw ApiException::invalidState('No pending memory_review approval found for this memory.');
        }

        $approval = app(MemoryService::class)->reject($approval, $request->user()->id, $validated['reason'] ?? null);

        return $this->data([
            'memory' => $this->transformMemory($memory->fresh()),
            'approval' => $this->transformApproval($approval),
        ]);
    }

    /** POST /memories/{id}/archive — status → archived (MemoryService::archive). */
    public function archive(Request $request, int $id)
    {
        $memory = $this->memoryFor($request, $id);
        $this->requireMutation($request);

        $memory = app(MemoryService::class)->archive($memory, 'user', $request->user()->id);

        return $this->data($this->transformMemory($memory));
    }

    /**
     * POST /memories/{id}/supersede — old version recorded in memory_versions,
     * new content becomes version+1 (§6.16.4 / PRD 6.16.4).
     */
    public function supersede(Request $request, int $id)
    {
        $memory = $this->memoryFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate([
            'content' => 'required|string',
            'reason' => 'sometimes|nullable|string',
        ]);

        $memory = app(MemoryService::class)->supersede(
            $memory,
            $validated['content'],
            $validated['reason'] ?? null,
            'user',
            $request->user()->id,
        );

        return $this->data($this->transformMemory($memory));
    }

    /** GET /memories/{id}/versions. */
    public function versions(Request $request, int $id)
    {
        $memory = $this->memoryFor($request, $id);

        $versions = MemoryVersion::where('memory_id', $memory->id)->orderBy('version')->get();

        return $this->data($versions->map(fn (MemoryVersion $v) => $this->transformMemoryVersion($v))->all());
    }

    /**
     * GET /memories/{id}/sources — source + linked execution/task (§4.2).
     */
    public function sources(Request $request, int $id)
    {
        $memory = $this->memoryFor($request, $id);
        $source = $this->transformMemory($memory)['source'];

        $execution = $memory->execution_id !== null ? $memory->execution : null;
        $task = $memory->task_id !== null ? Task::find($memory->task_id) : null;

        return $this->data([
            'source' => $source,
            'execution' => $execution !== null ? $this->transformExecution($execution) : null,
            'task' => $task !== null ? $this->transformTask($task) : null,
        ]);
    }

    /** GET /memories/{id}/history — audit_logs for this memory (§4.2). */
    public function history(Request $request, int $id)
    {
        $memory = $this->memoryFor($request, $id);

        $logs = AuditLog::where('workspace_id', $memory->workspace_id)
            ->where('resource_type', 'memory')
            ->where('resource_id', $memory->id)
            ->orderByDesc('id')
            ->get();

        return $this->data($logs->map(fn (AuditLog $log) => [
            'event' => $log->action,
            'at' => $log->created_at?->toIso8601String(),
            'actor' => ['type' => $log->actor_type, 'id' => (int) $log->actor_id],
        ])->all());
    }

    private function memoryFor(Request $request, int $id): Memory
    {
        $memory = Memory::where('workspace_id', $this->workspaceFor($request)->id)->find($id);

        if ($memory === null) {
            throw ApiException::notFound('Memory not found.');
        }

        return $memory;
    }
}
