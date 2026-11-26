<?php

namespace Tests\Feature;

use App\Models\Task;

class ResourceApiTest extends ApiContractTest
{
    public function test_dashboard_stats_match_seed_data(): void
    {
        $response = $this->actingAs($this->demoUser)
            ->getJson('/api/v1/dashboard/stats');

        $response
            ->assertStatus(200)
            // §4.2: bare shape, no data envelope.
            ->assertJsonStructure([
                'totals' => ['projects', 'activeProjects', 'agents', 'activeAgents', 'memories', 'pendingMemoryReviews'],
                'executions' => ['running', 'completedLast7Days', 'failedLast7Days'],
                'tasks' => ['blocked', 'inReview', 'completedLast7Days'],
                'pendingApprovals',
                'recentActivity',
            ])
            // §4.3 seed: 2 projects, 3 agents, 6 memories, 2 pending approvals.
            ->assertJsonPath('totals.projects', 2)
            ->assertJsonPath('totals.activeProjects', 1)
            ->assertJsonPath('totals.agents', 3)
            ->assertJsonPath('totals.memories', 6)
            ->assertJsonPath('totals.pendingMemoryReviews', 1)
            ->assertJsonPath('pendingApprovals', 2);

        $activity = $response->json('recentActivity');
        $this->assertIsArray($activity);
        $this->assertNotEmpty($activity, 'recentActivity must be seeded (audit rows for e1)');
    }

    public function test_missing_project_is_404_not_found(): void
    {
        $this->actingAs($this->demoUser)
            ->getJson('/api/v1/projects/999')
            ->assertStatus(404)
            ->assertJson([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Project not found.',
                ],
            ]);
    }

    public function test_memory_search_returns_results_envelope(): void
    {
        $this->actingAs($this->demoUser)
            ->postJson('/api/v1/memories/search', ['query' => 'session auth', 'scope' => 'workspace'])
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['results' => [
                ['id', 'scope', 'type', 'title', 'content'],
            ]]]);
    }

    public function test_executing_in_review_task_is_422_invalid_state(): void
    {
        $task = Task::where('status', 'in_review')->firstOrFail();

        $this->actingAs($this->demoUser)
            ->postJson("/api/v1/tasks/{$task->id}/execute")
            ->assertStatus(422)
            ->assertJson([
                'error' => [
                    'code' => 'invalid_state',
                    'message' => 'Task status does not allow execution.',
                    'details' => ['status' => 'in_review'],
                ],
            ]);
    }
}
