<?php

namespace App\Domain\Memory;

use App\Http\ApiException;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Memory;
use App\Models\MemoryRetrieval;
use App\Models\MemoryVersion;
use App\Models\Task;
use App\Models\Workspace;

/**
 * Memory lifecycle service (ARCHITECTURE.md 5.2, MAC-01/05/06/08).
 *
 * - propose(): agent-originated shared-scope memory lands in
 *   pending_review and raises a memory_review approval (MAC-06, APR-009).
 * - update()/archive()/delete()/supersede(): each writes a memory_versions
 *   row (MAC-05) and an audit row; delete enforces ownership (MAC-08).
 */
class MemoryService
{
    public function __construct(
        private readonly KeywordSearcher $searcher,
        private readonly MemoryScopeGuard $guard,
    ) {
    }

    /**
     * Agent proposes a memory during an execution (tool memory.propose,
     * MAC-01/06). Shared scopes (workspace/project) start pending_review
     * when the workspace policy requires review; task/agent scopes go
     * active directly.
     */
    public function propose(
        Workspace $workspace,
        Agent $agent,
        string $scope,
        string $type,
        string $title,
        string $content,
        ?int $executionId = null,
        ?int $projectId = null,
        ?int $taskId = null,
        float $confidence = 0.5,
        string $trust = 'medium',
        ?string $summary = null,
    ): Memory {
        $shared = in_array($scope, ['workspace', 'project'], true);
        $requiresReview = $shared && (bool) ($workspace->memoryPolicy()['require_review_for_shared_scopes'] ?? true);

        if ($scope === 'project' && $projectId === null) {
            $projectId = $this->defaultProjectFor($agent);
        }

        $memory = Memory::create([
            'workspace_id' => $workspace->id,
            'scope' => $scope,
            'type' => $type,
            'project_id' => $scope === 'project' ? $projectId : null,
            'agent_id' => $scope === 'agent' ? $agent->id : null,
            'task_id' => $scope === 'task' ? $taskId : null,
            'execution_id' => $executionId,
            'title' => $title,
            'content' => $content,
            'status' => $requiresReview ? 'pending_review' : 'active',
            'version' => 1,
            'confidence' => $confidence,
            'trust' => $trust,
            'source' => [
                'type' => 'execution',
                'ref_id' => $executionId,
                'actor_type' => 'agent',
                'actor_id' => $agent->id,
                'summary' => $summary ?? mb_substr($title, 0, 120),
            ],
            'created_by_type' => 'agent',
            'created_by_id' => $agent->id,
        ]);

        MemoryVersion::create([
            'memory_id' => $memory->id,
            'version' => 1,
            'content' => $content,
            'changed_by_type' => 'agent',
            'changed_by_id' => $agent->id,
            'created_at' => now(),
        ]);

        AuditLog::record(
            $workspace->id,
            'agent',
            $agent->id,
            'memory',
            $memory->id,
            'memory.created',
            ['status' => $memory->status, 'scope' => $scope],
            null,
            $executionId,
        );

        if ($requiresReview) {
            Approval::create([
                'workspace_id' => $workspace->id,
                'request_type' => 'memory_review',
                'memory_id' => $memory->id,
                'execution_id' => $executionId,
                'status' => 'pending',
                'requested_by_type' => 'agent',
                'requested_by_id' => $agent->id,
                'created_at' => now(),
            ]);

            AuditLog::record(
                $workspace->id,
                'agent',
                $agent->id,
                'memory',
                $memory->id,
                'memory.approval_requested',
                null,
                ['approval' => 'pending'],
                $executionId,
            );
        }

        return $memory->fresh();
    }

    /**
     * Manual user-created memory (POST /memories): directly active.
     */
    public function storeByUser(
        Workspace $workspace,
        int $userId,
        string $scope,
        string $type,
        string $title,
        string $content,
        ?int $projectId = null,
        ?int $agentId = null,
        ?int $taskId = null,
        float $confidence = 0.5,
        string $trust = 'medium',
    ): Memory {
        if ($scope === 'project' && $projectId === null) {
            throw ApiException::validation('projectId is required for project scope.');
        }
        if ($scope === 'agent' && $agentId === null) {
            throw ApiException::validation('agentId is required for agent scope.');
        }
        if ($scope === 'task' && $taskId === null) {
            throw ApiException::validation('taskId is required for task scope.');
        }

        $memory = Memory::create([
            'workspace_id' => $workspace->id,
            'scope' => $scope,
            'type' => $type,
            'project_id' => $projectId,
            'agent_id' => $agentId,
            'task_id' => $taskId,
            'title' => $title,
            'content' => $content,
            'status' => 'active',
            'version' => 1,
            'confidence' => $confidence,
            'trust' => $trust,
            'source' => [
                'type' => 'user',
                'ref_id' => null,
                'actor_type' => 'user',
                'actor_id' => $userId,
                'summary' => mb_substr($title, 0, 120),
            ],
            'created_by_type' => 'user',
            'created_by_id' => $userId,
        ]);

        MemoryVersion::create([
            'memory_id' => $memory->id,
            'version' => 1,
            'content' => $content,
            'changed_by_type' => 'user',
            'changed_by_id' => $userId,
            'created_at' => now(),
        ]);

        AuditLog::record($workspace->id, 'user', $userId, 'memory', $memory->id, 'memory.created', null, ['status' => 'active']);

        return $memory->fresh();
    }

    /**
     * PATCH /memories/{id} — a content/title change writes a new version
     * row (MAC-05) plus an audit row.
     */
    public function update(Memory $memory, array $fields, string $actorType, int $actorId): Memory
    {
        $changed = [];

        foreach (['title', 'content', 'confidence', 'expires_at'] as $field) {
            if (array_key_exists($field, $fields) && $fields[$field] !== null && $fields[$field] !== $memory->{$field}) {
                $changed[$field] = $fields[$field];
            }
        }

        if ($changed === []) {
            return $memory->fresh();
        }

        $memory->update($changed);
        $memory->refresh();

        if (isset($changed['content']) || isset($changed['title'])) {
            $version = $memory->version + 1;
            $memory->update(['version' => $version]);

            MemoryVersion::create([
                'memory_id' => $memory->id,
                'version' => $version,
                'content' => $memory->content,
                'changed_by_type' => $actorType,
                'changed_by_id' => $actorId,
                'reason' => 'edit',
                'created_at' => now(),
            ]);
        }

        AuditLog::record(
            $memory->workspace_id,
            $actorType,
            $actorId,
            'memory',
            $memory->id,
            'memory.updated',
            $changed,
            null,
        );

        return $memory->fresh();
    }

    /**
     * POST /memories/{id}/supersede — old content row recorded in
     * memory_versions, new content becomes version+1 (PRD 6.16.4).
     */
    public function supersede(Memory $memory, string $content, ?string $reason, string $actorType, int $actorId): Memory
    {
        MemoryVersion::create([
            'memory_id' => $memory->id,
            'version' => $memory->version,
            'content' => $memory->content,
            'changed_by_type' => $actorType,
            'changed_by_id' => $actorId,
            'reason' => 'superseded by version ' . ($memory->version + 1),
            'created_at' => now(),
        ]);

        $memory->update([
            'content' => $content,
            'version' => $memory->version + 1,
            'status' => 'active',
        ]);

        MemoryVersion::create([
            'memory_id' => $memory->id,
            'version' => $memory->version,
            'content' => $content,
            'changed_by_type' => $actorType,
            'changed_by_id' => $actorId,
            'reason' => $reason ?? 'supersede',
            'created_at' => now(),
        ]);

        AuditLog::record(
            $memory->workspace_id,
            $actorType,
            $actorId,
            'memory',
            $memory->id,
            'memory.superseded',
            ['version' => $memory->version - 1],
            ['version' => $memory->version, 'reason' => $reason],
        );

        return $memory->fresh();
    }

    public function archive(Memory $memory, string $actorType, int $actorId): Memory
    {
        $before = $memory->status;

        if ($before === 'archived') {
            throw ApiException::invalidState('Memory is already archived.');
        }

        $memory->update(['status' => 'archived']);

        AuditLog::record(
            $memory->workspace_id,
            $actorType,
            $actorId,
            'memory',
            $memory->id,
            'memory.archived',
            ['status' => $before],
            ['status' => 'archived'],
        );

        return $memory->fresh();
    }

    /**
     * DELETE /memories/{id} (MAC-08): ownership/retention check, remove
     * derived memory_retrievals, audit row.
     */
    public function delete(Memory $memory, string $actorType, int $actorId): void
    {
        $workspace = Workspace::find($memory->workspace_id);

        // Retention: active shared-scope memories cannot be hard-deleted
        // by an agent actor; they must be archived.
        if ($actorType === 'agent' && $memory->isSharedScope() && $memory->status === 'active') {
            throw ApiException::forbidden('Active shared-scope memory must be archived, not deleted, by an agent.');
        }

        $memory->retrievals()->delete();
        $memory->delete();

        AuditLog::record(
            $workspace?->id ?? 0,
            $actorType,
            $actorId,
            'memory',
            $memory->id,
            'memory.deleted',
            ['status' => $memory->status],
            null,
        );
    }

    /**
     * Approve a memory_review approval: memory → active (MAC-06),
     * decision recorded with an audit row.
     */
    public function approve(Approval $approval, ?int $userId): Approval
    {
        if ($approval->request_type !== 'memory_review') {
            throw ApiException::invalidState('Not a memory review approval.');
        }

        $memory = $approval->memory;
        if ($memory === null) {
            throw ApiException::notFound('Memory no longer exists.');
        }

        $memory->update([
            'status' => 'active',
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        $approval->update([
            'status' => 'approved',
            'decision_user_id' => $userId,
            'decided_at' => now(),
        ]);

        AuditLog::record(
            $approval->workspace_id,
            'user',
            $userId,
            'memory',
            $memory->id,
            'memory.approved',
            ['status' => 'pending_review'],
            ['status' => 'active'],
        );

        return $approval->fresh();
    }

    public function reject(Approval $approval, ?int $userId, ?string $reason): Approval
    {
        if ($approval->request_type !== 'memory_review') {
            throw ApiException::invalidState('Not a memory review approval.');
        }

        $memory = $approval->memory;
        if ($memory === null) {
            throw ApiException::notFound('Memory no longer exists.');
        }

        $memory->update(['status' => 'rejected']);

        $approval->update([
            'status' => 'rejected',
            'decision_user_id' => $userId,
            'decision_reason' => $reason,
            'decided_at' => now(),
        ]);

        AuditLog::record(
            $approval->workspace_id,
            'user',
            $userId,
            'memory',
            $memory->id,
            'memory.rejected',
            ['status' => 'pending_review'],
            ['status' => 'rejected', 'reason' => $reason],
        );

        return $approval->fresh();
    }

    /**
     * Runner-side retrieval with budget cap (ARCHITECTURE.md 5.3 step 4,
     * 5.4). Returns ranked memories + scores; retrieval rows are written
     * by recordRetrievals() after the execution row exists.
     *
     * @return array{memories: Memory[], scores: array<int, float>, keptIds: int[], truncated: bool}
     */
    public function retrieveForExecution(Agent $agent, Task $task, string $query, ?int $limit = null): array
    {
        $retrieval = $agent->memoryConfig()['retrieval'];
        $limit ??= $retrieval['max_memories'];
        $budget = $retrieval['context_budget_tokens'];

        $candidates = $this->guard->visibleToAgentForTask($agent, $task)->get()->all();
        $ranked = array_slice($this->searcher->search($query, $candidates), 0, $limit);

        // Context budget cap: trim the ranked list until it fits.
        $kept = [];
        $used = 0;
        foreach ($ranked as $entry) {
            $tokens = (int) ceil(strlen($entry['memory']->title . $entry['memory']->content) / 4);

            if ($used + $tokens > $budget && $kept !== []) {
                break;
            }

            $kept[] = $entry;
            $used += $tokens;
        }

        $keptIds = array_map(static fn ($e) => $e['memory']->id, $kept);

        return [
            'memories' => array_map(static fn ($e) => $e['memory'], $kept),
            'scores' => array_map(static fn ($e) => $e['score'], $kept),
            'keptIds' => $keptIds,
            'truncated' => count($ranked) > count($kept),
        ];
    }

    /**
     * Persist memory_retrievals for a finished execution (MAC-02 audit,
     * OBS-011) and stamp last_accessed_at on each memory used.
     *
     * @param  int[]  $memoryIds   ranked, best first
     * @param  float[]  $scores
     */
    public function recordRetrievals(int $executionId, array $memoryIds, array $scores = []): void
    {
        foreach ($memoryIds as $i => $memoryId) {
            MemoryRetrieval::create([
                'execution_id' => $executionId,
                'memory_id' => $memoryId,
                'rank' => $i + 1,
                'score' => $scores[$i] ?? 0.0,
                'retrieved_at' => now(),
            ]);

            Memory::whereKey($memoryId)->update(['last_accessed_at' => now()]);
        }
    }

    private function defaultProjectFor(Agent $agent): ?int
    {
        return $agent->projects()->orderBy('projects.id')->value('projects.id');
    }
}
