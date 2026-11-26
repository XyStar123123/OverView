<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Memory\MemoryService;
use App\Enums\ApprovalStatus;
use App\Http\ApiException;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Http\Request;

class ApprovalsController extends ApiV1Controller
{
    /** GET /approvals — query: status? requestType?; pending first, then desc (§4.2). */
    public function index(Request $request)
    {
        $workspace = $this->workspaceFor($request);

        $approvals = Approval::where('workspace_id', $workspace->id)
            ->when($request->query('status'), fn ($a) => $a->where('status', $request->query('status')))
            ->when($request->query('requestType'), fn ($a) => $a->where('request_type', $request->query('requestType')))
            ->orderByRaw("case status when 'pending' then 0 else 1 end")
            ->orderByDesc('id')
            ->get();

        return $this->data($approvals->map(fn (Approval $a) => $this->transformApproval($a))->all());
    }

    /** GET /approvals/{id}. */
    public function show(Request $request, int $id)
    {
        return $this->data($this->transformApproval($this->approvalFor($request, $id)));
    }

    /**
     * POST /approvals/{id}/approve (§4.2, APR-007):
     * task_review → task done; memory_review → memory active.
     * decision_user_id = session user.
     */
    public function approve(Request $request, int $id)
    {
        $approval = $this->approvalFor($request, $id);
        $this->requireMutation($request);

        if ($approval->status !== ApprovalStatus::Pending->value) {
            throw ApiException::invalidState('Approval is no longer pending.', ['status' => $approval->status]);
        }

        $userId = $request->user()->id;

        if ($approval->request_type === 'memory_review') {
            $approval = app(MemoryService::class)->approve($approval, $userId);

            return $this->data($this->transformApproval($approval));
        }

        $task = Task::find($approval->task_id);

        if ($task === null) {
            throw ApiException::notFound('Linked task no longer exists.');
        }

        $taskBefore = $task->status;
        $task->update(['status' => 'done']);

        $approval->update([
            'status' => 'approved',
            'decision_user_id' => $userId,
            'decided_at' => now(),
        ]);

        AuditLog::record(
            $task->workspace_id,
            'user',
            $userId,
            'task',
            $task->id,
            'task.status_updated',
            ['status' => $taskBefore],
            ['status' => 'done'],
            $approval->execution_id,
        );

        return $this->data($this->transformApproval($approval->fresh()));
    }

    /**
     * POST /approvals/{id}/reject ({ reason? }, §4.2, APR-004):
     * task_review → task in_progress + comment; memory_review → memory rejected.
     */
    public function reject(Request $request, int $id)
    {
        $approval = $this->approvalFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate(['reason' => 'sometimes|nullable|string']);
        $reason = $validated['reason'] ?? null;

        if ($approval->status !== ApprovalStatus::Pending->value) {
            throw ApiException::invalidState('Approval is no longer pending.', ['status' => $approval->status]);
        }

        $userId = $request->user()->id;

        if ($approval->request_type === 'memory_review') {
            $approval = app(MemoryService::class)->reject($approval, $userId, $reason);

            return $this->data($this->transformApproval($approval));
        }

        $task = Task::find($approval->task_id);

        if ($task === null) {
            throw ApiException::notFound('Linked task no longer exists.');
        }

        $taskBefore = $task->status;
        $task->update(['status' => 'in_progress']);

        TaskComment::create([
            'task_id' => $task->id,
            'actor_type' => 'user',
            'actor_id' => $userId,
            'body' => 'Review rejected' . ($reason !== null && $reason !== '' ? ': ' . $reason : '.'),
            'execution_id' => $approval->execution_id,
            'created_at' => now(),
        ]);

        $approval->update([
            'status' => 'rejected',
            'decision_user_id' => $userId,
            'decision_reason' => $reason,
            'decided_at' => now(),
        ]);

        AuditLog::record(
            $task->workspace_id,
            'user',
            $userId,
            'task',
            $task->id,
            'task.status_updated',
            ['status' => $taskBefore],
            ['status' => 'in_progress'],
            $approval->execution_id,
        );

        return $this->data($this->transformApproval($approval->fresh()));
    }

    private function approvalFor(Request $request, int $id): Approval
    {
        $approval = Approval::where('workspace_id', $this->workspaceFor($request)->id)->find($id);

        if ($approval === null) {
            throw ApiException::notFound('Approval not found.');
        }

        return $approval;
    }
}
