<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'actor_type',
        'actor_id',
        'resource_type',
        'resource_id',
        'action',
        'before',
        'after',
        'execution_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Write one audit row (ARCHITECTURE.md 3.9 / 10.5). Sensitive mutations
     * MUST call this; actor identity includes agent + execution context.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        int $workspaceId,
        string $actorType,
        ?int $actorId,
        string $resourceType,
        ?int $resourceId,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?int $executionId = null,
    ): self {
        return static::create([
            'workspace_id' => $workspaceId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'execution_id' => $executionId,
            'created_at' => now(),
        ]);
    }
}
