<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Approval;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\ExecutionEvent;
use App\Models\Gateway;
use App\Models\Memory;
use App\Models\MemoryRetrieval;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Runtime;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * §4.3 seed data (spec binding). Deterministic; timestamps relative to now().
 * Idempotent: natural-key firstOrCreate, count-guarded event/retrieval/audit
 * inserts. Includes the seeded artifact file at storage/app/overview/.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // users: demo — demo@overview.local / demo, role owner (§4.3).
        $demo = User::firstOrCreate(
            ['email' => 'demo@overview.local'],
            ['name' => 'Demo', 'password_hash' => Hash::make('demo')],
        );
        // Repair pre-existing rows seeded with a plaintext password_hash.
        if (!Hash::check('demo', $demo->password_hash)) {
            $demo->forceFill(['password_hash' => Hash::make('demo')])->save();
        }

        // ws1 "Demo Workspace".
        $ws = Workspace::firstOrCreate(
            ['slug' => 'demo-workspace'],
            [
                'name' => 'Demo Workspace',
                'default_gateway_id' => null, // backfilled after gateways exist
                'default_model' => 'mock-llm-lite',
                'default_runtime_id' => null, // backfilled after runtimes exist
                'memory_policy' => ['require_review_for_shared_scopes' => true],
            ],
        );

        WorkspaceMember::firstOrCreate(
            ['workspace_id' => $ws->id, 'user_id' => $demo->id],
            ['role' => 'owner'],
        );

        // g1 "Mock Gateway" + r1 "Mock Runner".
        $gateway = Gateway::firstOrCreate(
            ['workspace_id' => $ws->id, 'name' => 'Mock Gateway'],
            [
                'type' => 'mock',
                'base_url' => 'mock://local',
                'api_key_ref' => 'MOCK_KEY_REF',
                'status' => 'connected',
                'api_format' => 'chat',
                'models' => ['mock-llm-1', 'mock-llm-lite'],
                'last_checked_at' => $now->copy()->subHours(1),
            ],
        );

        $runtime = Runtime::firstOrCreate(
            ['workspace_id' => $ws->id, 'name' => 'Mock Runner'],
            [
                'adapter' => 'mock',
                'status' => 'healthy',
                'capabilities' => ['context_injection', 'tool_based_memory_access', 'execution_state_recovery'],
            ],
        );

        $ws->fill(['default_gateway_id' => $gateway->id, 'default_runtime_id' => $runtime->id])->save();

        // p1 / p2.
        $p1 = Project::firstOrCreate(
            ['workspace_id' => $ws->id, 'slug' => 'overview-core'],
            [
                'name' => 'OverView Core',
                'description' => 'Core agent orchestration platform: tasks, executions, memory.',
                'objectives' => ['Ship the agent execution loop', 'Make memory review-first'],
                'status' => 'active',
                'priority' => 'medium',
                'owner_user_id' => $demo->id,
            ],
        );

        $p2 = Project::firstOrCreate(
            ['workspace_id' => $ws->id, 'slug' => 'website-redesign'],
            [
                'name' => 'Website Redesign',
                'description' => 'Redesign of the marketing site on the 4px design scale.',
                'objectives' => ['New visual language', 'Accessible components'],
                'status' => 'planning',
                'priority' => 'high',
                'owner_user_id' => $demo->id,
            ],
        );

        ProjectMember::firstOrCreate(['project_id' => $p1->id, 'user_id' => $demo->id]);
        ProjectMember::firstOrCreate(['project_id' => $p2->id, 'user_id' => $demo->id]);

        // a1-a3.
        $a1 = Agent::firstOrCreate(
            ['workspace_id' => $ws->id, 'slug' => 'backend-developer'],
            [
                'name' => 'Backend Developer',
                'role' => 'developer',
                'description' => 'Backend API and orchestration work.',
                'instructions' => 'Follow repo conventions; thin controllers; audit every mutation.',
                'status' => 'available',
                'gateway_id' => $gateway->id,
                'default_model' => 'mock-llm-1',
                'runtime_id' => $runtime->id,
                'tool_allowlist' => ['task.update', 'artifact.submit', 'memory.search', 'memory.propose', 'memory.store', 'memory.get', 'memory.list'],
                'allowed_memory_scopes' => ['agent', 'project', 'task'],
                'memory_retrieval' => ['enabled' => true, 'max_memories' => 5, 'context_budget_tokens' => 2000],
                'created_by' => $demo->id,
            ],
        );

        $a2 = Agent::firstOrCreate(
            ['workspace_id' => $ws->id, 'slug' => 'qa-engineer'],
            [
                'name' => 'QA Engineer',
                'role' => 'qa',
                'description' => 'Verification and bug triage.',
                'instructions' => 'Reproduce, verify, and record outcomes.',
                'status' => 'available',
                'gateway_id' => $gateway->id,
                'default_model' => 'mock-llm-lite',
                'runtime_id' => $runtime->id,
                'tool_allowlist' => ['task.update', 'memory.search', 'memory.list'],
                'allowed_memory_scopes' => ['project', 'task'],
                'memory_retrieval' => ['enabled' => true, 'max_memories' => 5, 'context_budget_tokens' => 2000],
                'created_by' => $demo->id,
            ],
        );

        $a3 = Agent::firstOrCreate(
            ['workspace_id' => $ws->id, 'slug' => 'researcher'],
            [
                'name' => 'Researcher',
                'role' => 'researcher',
                'description' => 'Investigations and reference study.',
                'instructions' => 'Study real patterns; cite sources.',
                'status' => 'offline',
                'gateway_id' => $gateway->id,
                'default_model' => 'mock-llm-lite',
                'runtime_id' => $runtime->id,
                'tool_allowlist' => ['memory.search', 'memory.list'],
                'allowed_memory_scopes' => ['workspace', 'project'],
                'memory_retrieval' => ['enabled' => true, 'max_memories' => 5, 'context_budget_tokens' => 2000],
                'created_by' => $demo->id,
            ],
        );

        $a1->projects()->syncWithoutDetaching([$p1->id]);
        $a2->projects()->syncWithoutDetaching([$p1->id]);
        $a3->projects()->syncWithoutDetaching([$p2->id]);

        // t1-t4 (all p1).
        $taskBase = ['workspace_id' => $ws->id, 'project_id' => $p1->id, 'creator_type' => 'user', 'creator_id' => $demo->id];

        $t1 = Task::firstOrCreate(
            ['workspace_id' => $ws->id, 'project_id' => $p1->id, 'title' => 'Draft public API contract'],
            $taskBase + [
                'type' => 'feature',
                'status' => 'todo',
                'priority' => 'high',
                'assignee_type' => 'agent',
                'assignee_id' => $a1->id,
                'override_agent_model' => true,
                'override_gateway_id' => $gateway->id,
                'override_model' => 'mock-llm-lite', // demo AC-13 model override
            ],
        );

        $t2 = Task::firstOrCreate(
            ['workspace_id' => $ws->id, 'project_id' => $p1->id, 'title' => 'Implement session auth'],
            $taskBase + [
                'type' => 'feature',
                'status' => 'in_progress',
                'priority' => 'medium',
                'assignee_type' => 'agent',
                'assignee_id' => $a1->id,
            ],
        );

        $t3 = Task::firstOrCreate(
            ['workspace_id' => $ws->id, 'project_id' => $p1->id, 'title' => 'Write project memory guidelines'],
            $taskBase + [
                'type' => 'documentation',
                'status' => 'in_review',
                'priority' => 'medium',
                'description' => 'Document how project memory should be written and reviewed.',
                'assignee_type' => 'agent',
                'assignee_id' => $a1->id,
            ],
        );

        $t4 = Task::firstOrCreate(
            ['workspace_id' => $ws->id, 'project_id' => $p1->id, 'title' => 'Fix kanban board drag'],
            $taskBase + [
                'type' => 'bug',
                'status' => 'done',
                'priority' => 'low',
                'assignee_type' => 'agent',
                'assignee_id' => $a2->id,
            ],
        );

        // m1-m6 (created before e1: m4/m5/m6 reference execution e1; the two
        // memory_retrievals for e1 are m2 + m4).
        $userSource = fn (?int $executionId) => [
            'type' => 'user',
            'ref_id' => $executionId,
            'actor_type' => 'user',
            'actor_id' => $demo->id,
            'summary' => 'Manually created by workspace owner',
        ];

        $m1 = Memory::firstOrCreate(
            ['workspace_id' => $ws->id, 'title' => 'Workspace coding conventions'],
            [
                'scope' => 'workspace',
                'type' => 'semantic',
                'content' => 'English identifiers, portable migrations, one file per model, thin controllers.',
                'status' => 'active',
                'version' => 1,
                'confidence' => 0.9,
                'trust' => 'high',
                'source' => $userSource(null),
                'created_by_type' => 'user',
                'created_by_id' => $demo->id,
                'approved_by' => $demo->id,
                'approved_at' => $now->copy()->subDays(84)->startOfDay(),
                'last_accessed_at' => $now->copy()->subHours(24),
                'created_at' => $now->copy()->subDays(84),
                'updated_at' => $now->copy()->subDays(84),
            ],
        );

        $m2 = Memory::firstOrCreate(
            ['workspace_id' => $ws->id, 'title' => 'Database: Postgres production, SQLite local dev'],
            [
                'scope' => 'project',
                'type' => 'semantic',
                'project_id' => $p1->id,
                'content' => 'Production runs Postgres; local dev and CI use SQLite. Migrations must stay portable across both.',
                'status' => 'active',
                'version' => 1,
                'confidence' => 0.9,
                'trust' => 'high',
                'source' => $userSource(null),
                'created_by_type' => 'user',
                'created_by_id' => $demo->id,
                'approved_by' => $demo->id,
                'approved_at' => $now->copy()->subDays(60)->startOfDay(),
                'last_accessed_at' => $now->copy()->subMinutes(5),
                'created_at' => $now->copy()->subDays(60),
                'updated_at' => $now->copy()->subDays(60),
            ],
        );

        $m3 = Memory::firstOrCreate(
            ['workspace_id' => $ws->id, 'title' => 'Design tokens: 4px scale, status colors'],
            [
                'scope' => 'project',
                'type' => 'semantic',
                'project_id' => $p2->id,
                'content' => 'Spacing scale 4/8/12/16/24/32 px; pending/awaiting states use amber-700 #B45309.',
                'status' => 'active',
                'version' => 1,
                'confidence' => 0.9,
                'trust' => 'high',
                'source' => $userSource(null),
                'created_by_type' => 'user',
                'created_by_id' => $demo->id,
                'approved_by' => $demo->id,
                'approved_at' => $now->copy()->subDays(30)->startOfDay(),
                'created_at' => $now->copy()->subDays(30),
                'updated_at' => $now->copy()->subDays(30),
            ],
        );

        // m4/m5/m6 carry execution e1 provenance; e1 is created below, so use
        // update to stamp execution_id after the execution row exists.
        $m4 = Memory::firstOrCreate(
            ['workspace_id' => $ws->id, 'title' => 'Debugged session cookie CORS issue'],
            [
                'scope' => 'agent',
                'type' => 'episodic',
                'agent_id' => $a1->id,
                'content' => 'Session cookies were dropped on the API origin until SameSite=None + explicit CORS credentials were set on the browser side.',
                'status' => 'active',
                'version' => 1,
                'confidence' => 0.8,
                'trust' => 'high',
                'source' => [
                    'type' => 'execution',
                    'ref_id' => null,
                    'actor_type' => 'agent',
                    'actor_id' => $a1->id,
                    'summary' => 'Stored by Backend Developer during execution',
                ],
                'created_by_type' => 'agent',
                'created_by_id' => $a1->id,
                'approved_by' => $demo->id,
                'approved_at' => $now->copy()->subDays(7)->startOfDay(),
                'last_accessed_at' => $now->copy()->subMinutes(5),
                'created_at' => $now->copy()->subDays(7),
                'updated_at' => $now->copy()->subDays(7),
            ],
        );

        $m5 = Memory::firstOrCreate(
            ['workspace_id' => $ws->id, 'title' => 'Working context for t3: decisions & progress'],
            [
                'scope' => 'task',
                'type' => 'working',
                'task_id' => $t3->id,
                'content' => 't3 decisions: guidelines document drafted; structure = scope rules, provenance, review flow. Remaining: publish + link review.',
                'status' => 'active',
                'version' => 1,
                'confidence' => 0.7,
                'trust' => 'medium',
                'source' => [
                    'type' => 'execution',
                    'ref_id' => null,
                    'actor_type' => 'agent',
                    'actor_id' => $a1->id,
                    'summary' => 'Working memory written by Backend Developer',
                ],
                'created_by_type' => 'agent',
                'created_by_id' => $a1->id,
                'expires_at' => $now->copy()->addDays(7),
                'created_at' => $now->copy()->subMinutes(4),
                'updated_at' => $now->copy()->subMinutes(4),
            ],
        );

        $m6 = Memory::firstOrCreate(
            ['workspace_id' => $ws->id, 'title' => 'API v2 decisions: versioned routes'],
            [
                'scope' => 'project',
                'type' => 'semantic',
                'project_id' => $p1->id,
                'content' => 'Proposed: move to versioned routes under /api/v2 with the same resource shapes; old v1 stays read-only during transition.',
                'status' => 'pending_review',
                'version' => 1,
                'confidence' => 0.6,
                'trust' => 'medium',
                'source' => [
                    'type' => 'execution',
                    'ref_id' => null,
                    'actor_type' => 'agent',
                    'actor_id' => $a1->id,
                    'summary' => 'Proposed by Backend Developer via memory.propose',
                ],
                'created_by_type' => 'agent',
                'created_by_id' => $a1->id,
                'created_at' => $now->copy()->subMinutes(2),
                'updated_at' => $now->copy()->subMinutes(2),
            ],
        );

        // e1 (t3, a1) — completed; timestamps relative to now().
        $started = $now->copy()->subMinutes(5)->startOfSecond();
        $finished = $started->copy()->addSeconds(3)->addMilliseconds(200);

        $e1 = Execution::firstOrCreate(
            ['task_id' => $t3->id, 'agent_id' => $a1->id, 'status' => 'completed'],
            [
                'workspace_id' => $ws->id,
                'project_id' => $p1->id,
                'runtime_id' => $runtime->id,
                'gateway_id' => $gateway->id,
                'model' => 'mock-llm-1',
                'model_resolution' => ['gateway' => 'mock-gateway', 'model' => 'mock-llm-1', 'source' => 'agent'],
                'status' => 'completed',
                'started_at' => $started,
                'finished_at' => $finished,
                'usage' => [
                    'input_tokens' => 412,
                    'output_tokens' => 188,
                    'context_tokens' => 1240,
                    'tool_invocations' => 3,
                    'retrieved_memory_count' => 2,
                    'estimated_cost' => 0,
                    'duration_ms' => 3200,
                ],
                'failure_reason' => null,
                'created_at' => $started,
                'updated_at' => $finished,
            ],
        );

        Memory::where('id', $m4->id)->update(['execution_id' => $e1->id]);
        Memory::where('id', $m5->id)->update(['execution_id' => $e1->id]);
        Memory::where('id', $m6->id)->update(['execution_id' => $e1->id]);
        Memory::where('id', $m4->id)->update(['source' => ['type' => 'execution', 'ref_id' => $e1->id, 'actor_type' => 'agent', 'actor_id' => $a1->id, 'summary' => 'Stored by Backend Developer during execution']]);
        Memory::where('id', $m5->id)->update(['source' => ['type' => 'execution', 'ref_id' => $e1->id, 'actor_type' => 'agent', 'actor_id' => $a1->id, 'summary' => 'Working memory written by Backend Developer during execution']]);
        Memory::where('id', $m6->id)->update(['source' => ['type' => 'execution', 'ref_id' => $e1->id, 'actor_type' => 'agent', 'actor_id' => $a1->id, 'summary' => 'Proposed by Backend Developer via memory.propose during execution']]);

        // ar1 + its file at storage/app/overview/.
        $artifactFile = "ar1-memory-guidelines-draft.md";
        $artifactContent = <<<MD
        # Project Memory Guidelines (draft)

        ## Scope
        - workspace: conventions shared by all projects
        - project: decisions binding one project
        - agent: episodic learnings of one agent
        - task: working context for one task (expires)

        ## Provenance
        Every memory records how it was created: user, agent, or execution.
        Executed work writes working memories; shared-scope memories are
        proposed and reviewed, never auto-activated.

        ## Review flow
        - proposed memories start as pending_review
        - approval activates them; rejection records the reason
        - superseded versions stay in memory_versions for the audit trail
        MD;
        Storage::disk('overview')->put($artifactFile, $artifactContent);

        Artifact::firstOrCreate(
            ['workspace_id' => $ws->id, 'task_id' => $t3->id, 'name' => 'memory-guidelines-draft.md'],
            [
                'project_id' => $p1->id,
                'execution_id' => $e1->id,
                'agent_id' => $a1->id,
                'artifact_type' => 'document',
                'file_path' => $artifactFile,
                'content_type' => 'text/markdown',
                'size_bytes' => strlen($artifactContent),
                'version' => 1,
                'status' => 'reviewed',
                'reviewed_by' => null,
                'review_note' => null,
                'created_at' => $now->copy()->subMinutes(3),
                'updated_at' => $now->copy()->subMinutes(3),
            ],
        );

        // ap1 / ap2 (both pending).
        Approval::firstOrCreate(
            ['request_type' => 'task_review', 'task_id' => $t3->id, 'execution_id' => $e1->id],
            [
                'workspace_id' => $ws->id,
                'status' => 'pending',
                'requested_by_type' => 'agent',
                'requested_by_id' => $a1->id,
                'created_at' => $now->copy()->subMinutes(2),
            ],
        );

        Approval::firstOrCreate(
            ['request_type' => 'memory_review', 'memory_id' => $m6->id, 'execution_id' => $e1->id],
            [
                'workspace_id' => $ws->id,
                'status' => 'pending',
                'requested_by_type' => 'agent',
                'requested_by_id' => $a1->id,
                'created_at' => $now->copy()->subMinutes(2),
            ],
        );

        // ~13 events for e1 (mirrors the §5.3 sequence).
        if (ExecutionEvent::where('execution_id', $e1->id)->count() === 0) {
            $events = [
                ['task_received', ['task_id' => $t3->id, 'title' => $t3->title]],
                ['agent_initialized', ['runtime' => $runtime->id, 'agent' => $a1->id, 'status' => 'working']],
                ['memory_retrieval', ['query' => 'project memory guidelines', 'retrieved' => [['memoryId' => $m2->id, 'score' => 0.62], ['memoryId' => $m4->id, 'score' => 0.41]], 'budgetTokens' => 2000, 'truncated' => false]],
                ['context_assembled', ['sections' => ['project', 'task', 'agent_instructions', 'memory(2)'], 'promptTokens' => 412]],
                ['llm_request', ['model' => 'mock-llm-1', 'inputTokens' => 412]],
                ['llm_response', ['model' => 'mock-llm-1', 'outputTokens' => 188, 'text' => sprintf('[mock-llm] Task "%s": analyzed project context and 2 retrieved memories. Steps performed: outlined guidelines, drafted doc. Deliverable: memory-guidelines-draft.md.', $t3->title)]],
                ['tool_requested', ['tool' => 'artifact.submit']],
                ['tool_executed', ['tool' => 'artifact.submit', 'artifactId' => Artifact::where('task_id', $t3->id)->first()->id ?? null]],
                ['artifact_submitted', ['artifactId' => Artifact::where('task_id', $t3->id)->first()->id ?? null, 'name' => 'memory-guidelines-draft.md']],
                ['memory_stored', ['memoryId' => $m5->id, 'scope' => 'task', 'status' => 'active']],
                ['approval_requested', ['approvalType' => 'memory_review', 'memoryId' => $m6->id]],
                ['task_status_updated', ['from' => 'in_progress', 'to' => 'in_review']],
                ['execution_completed', ['usage' => ['inputTokens' => 412, 'outputTokens' => 188]]],
            ];

            foreach ($events as $i => [$type, $payload]) {
                ExecutionEvent::create([
                    'execution_id' => $e1->id,
                    'created_at' => $started->copy()->addMilliseconds($i * 200),
                    'sequence' => $i + 1,
                    'type' => $type,
                    'payload' => $payload,
                ]);
            }
        }

        // 2 memory_retrievals for e1 (m2, m4).
        if (MemoryRetrieval::where('execution_id', $e1->id)->count() === 0) {
            MemoryRetrieval::create(['execution_id' => $e1->id, 'memory_id' => $m2->id, 'rank' => 1, 'score' => 0.62, 'retrieved_at' => $started->copy()->addMilliseconds(400)]);
            MemoryRetrieval::create(['execution_id' => $e1->id, 'memory_id' => $m4->id, 'rank' => 2, 'score' => 0.41, 'retrieved_at' => $started->copy()->addMilliseconds(400)]);
        }

        // ~10 audit rows (fills dashboard recent activity + memory history).
        if (AuditLog::where('workspace_id', $ws->id)->count() === 0) {
            $rows = [
                [$demo, 'user', $demo->id, 'task', $t1->id, 'task.created', null, ['title' => $t1->title, 'status' => 'todo'], null, $now->copy()->subDays(12)->startOfHour()],
                [$demo, 'user', $demo->id, 'task', $t2->id, 'task.created', null, ['title' => $t2->title, 'status' => 'todo'], null, $now->copy()->subDays(10)->startOfHour()],
                [$demo, 'user', $demo->id, 'task', $t3->id, 'task.created', null, ['title' => $t3->title, 'status' => 'todo'], null, $now->copy()->subDays(3)->startOfHour()],
                [$demo, 'user', $demo->id, 'task', $t4->id, 'task.created', null, ['title' => $t4->title, 'status' => 'todo'], null, $now->copy()->subDays(3)->startOfHour()->addMinutes(5)],
                [$a1, 'agent', $a1->id, 'task', $t3->id, 'task.status_updated', ['status' => 'in_progress'], ['status' => 'in_review'], $e1->id, $started->copy()->addSeconds(2)->addMilliseconds(400)],
                [$a1, 'agent', $a1->id, 'execution', $e1->id, 'execution.completed', ['status' => 'running'], ['status' => 'completed'], $e1->id, $finished],
                [$a1, 'agent', $a1->id, 'artifact', Artifact::where('task_id', $t3->id)->first()->id, 'artifact.submitted', null, ['name' => 'memory-guidelines-draft.md'], $e1->id, $started->copy()->addSeconds(1)->addMilliseconds(900)],
                [$a1, 'agent', $a1->id, 'memory', $m5->id, 'memory.created', null, ['title' => $m5->title, 'status' => 'active'], $e1->id, $started->copy()->addSeconds(2)->addMilliseconds(100)],
                [$a1, 'agent', $a1->id, 'memory', $m6->id, 'memory.approval_requested', null, ['title' => $m6->title, 'status' => 'pending_review'], $e1->id, $started->copy()->addSeconds(2)->addMilliseconds(200)],
                [$demo, 'user', $demo->id, 'memory', $m1->id, 'memory.created', null, ['title' => $m1->title, 'status' => 'active'], null, $now->copy()->subDays(84)],
            ];

            foreach ($rows as [, $actorType, $actorId, $resourceType, $resourceId, $action, $before, $after, $executionId, $at]) {
                AuditLog::record(
                    $ws->id,
                    $actorType,
                    $actorId,
                    $resourceType,
                    $resourceId,
                    $action,
                    $before,
                    $after,
                    $executionId,
                )->update(['created_at' => $at]);
            }
        }
    }
}
