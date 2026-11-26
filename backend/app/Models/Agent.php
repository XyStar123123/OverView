<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'avatar',
        'description',
        'role',
        'instructions',
        'version',
        'status',
        'gateway_id',
        'default_model',
        'runtime_id',
        'tool_allowlist',
        'memory_enabled',
        'allowed_memory_scopes',
        'memory_retrieval',
        'execution_budget',
        'concurrency_limit',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tool_allowlist' => 'array',
            'memory_enabled' => 'boolean',
            'allowed_memory_scopes' => 'array',
            'memory_retrieval' => 'array',
            'execution_budget' => 'array',
        ];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function gateway()
    {
        return $this->belongsTo(Gateway::class);
    }

    public function runtime()
    {
        return $this->belongsTo(Runtime::class);
    }

    public function projects()
    {
        return $this->belongsToMany(Project::class, 'project_agents');
    }

    public function executions()
    {
        return $this->hasMany(Execution::class);
    }

    /**
     * Memory configuration as exposed by the API (ARCHITECTURE.md 4.1 Agent).
     */
    public function memoryConfig(): array
    {
        $retrieval = $this->memory_retrieval ?? ['enabled' => true, 'max_memories' => 5, 'context_budget_tokens' => 2000];

        return [
            'enabled' => (bool) $this->memory_enabled,
            'allowed_scopes' => $this->allowed_memory_scopes ?? [],
            'retrieval' => [
                'enabled' => (bool) ($retrieval['enabled'] ?? true),
                'max_memories' => (int) ($retrieval['max_memories'] ?? 5),
                'context_budget_tokens' => (int) ($retrieval['context_budget_tokens'] ?? 2000),
            ],
        ];
    }
}
