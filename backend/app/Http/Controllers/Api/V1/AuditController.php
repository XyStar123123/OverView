<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditController extends ApiV1Controller
{
    /**
     * GET /audit-logs — query: resourceType? resourceId? limit? (default 50,
     * max 100, desc) (§4.2).
     */
    public function index(Request $request)
    {
        $workspace = $this->workspaceFor($request);

        $logs = AuditLog::where('workspace_id', $workspace->id)
            ->when($request->query('resourceType'), fn ($l) => $l->where('resource_type', $request->query('resourceType')))
            ->when($request->query('resourceId'), fn ($l) => $l->where('resource_id', $request->query('resourceId')))
            ->orderByDesc('id')
            ->limit(min(100, max(1, (int) ($request->query('limit') ?? 50))))
            ->get();

        return $this->data($logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'actorType' => $log->actor_type,
            'actorId' => (int) $log->actor_id,
            'resourceType' => $log->resource_type,
            'resourceId' => (int) $log->resource_id,
            'action' => $log->action,
            'before' => $log->before,
            'after' => $log->after,
            'executionId' => $log->execution_id,
            'at' => $log->created_at?->toIso8601String(),
        ])->all());
    }
}
