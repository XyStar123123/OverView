<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentStatus;
use App\Enums\ApprovalStatus;
use App\Enums\ExecutionStatus;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\Memory;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;

class DashboardController extends ApiV1Controller
{
    /**
     * GET /dashboard/stats — bare contract shape (§4.2):
     * { totals, executions, tasks, pendingApprovals, recentActivity }.
     * The frontend client.ts reads the payload bare (no data envelope).
     */
    public function stats(Request $request)
    {
        $workspace = $this->workspaceFor($request);
        $weekAgo = now()->subDays(7);

        $executions = Execution::where('workspace_id', $workspace->id)->get();

        $execStats = [
            'running' => $executions->filter(
                fn ($e) => in_array($e->status, [
                    ExecutionStatus::Queued->value,
                    ExecutionStatus::Running->value,
                    ExecutionStatus::Waiting->value,
                ], true)
            )->count(),
            'completedLast7Days' => Execution::where('workspace_id', $workspace->id)
                ->where('status', ExecutionStatus::Completed->value)
                ->where('finished_at', '>=', $weekAgo)
                ->count(),
            'failedLast7Days' => Execution::where('workspace_id', $workspace->id)
                ->where('status', ExecutionStatus::Failed->value)
                ->where('finished_at', '>=', $weekAgo)
                ->count(),
        ];

        $taskStats = [
            'blocked' => Task::where('workspace_id', $workspace->id)->where('status', 'blocked')->count(),
            'inReview' => Task::where('workspace_id', $workspace->id)->where('status', 'in_review')->count(),
            'completedLast7Days' => Task::where('workspace_id', $workspace->id)
                ->where('status', 'done')
                ->where('updated_at', '>=', $weekAgo)
                ->count(),
        ];

        return response()->json([
            'totals' => [
                'projects' => Project::where('workspace_id', $workspace->id)->count(),
                'activeProjects' => Project::where('workspace_id', $workspace->id)->where('status', 'active')->count(),
                'agents' => Agent::where('workspace_id', $workspace->id)->count(),
                'activeAgents' => Agent::where('workspace_id', $workspace->id)->where('status', AgentStatus::Available->value)->count(),
                'memories' => Memory::where('workspace_id', $workspace->id)->count(),
                'pendingMemoryReviews' => Approval::where('workspace_id', $workspace->id)
                    ->where('request_type', 'memory_review')
                    ->where('status', ApprovalStatus::Pending->value)
                    ->count(),
            ],
            'executions' => $execStats,
            'tasks' => $taskStats,
            'pendingApprovals' => Approval::where('workspace_id', $workspace->id)
                ->where('status', ApprovalStatus::Pending->value)
                ->count(),
            'recentActivity' => AuditLog::where('workspace_id', $workspace->id)
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'actor' => ['type' => $log->actor_type, 'id' => (int) $log->actor_id],
                    'action' => $log->action,
                    'resourceType' => $log->resource_type,
                    'resourceId' => (int) $log->resource_id,
                    'at' => $log->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }
}
