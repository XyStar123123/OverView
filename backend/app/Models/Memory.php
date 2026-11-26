<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Memory extends Model
{
    protected $fillable = [
        'workspace_id',
        'scope',
        'type',
        'project_id',
        'agent_id',
        'task_id',
        'execution_id',
        'title',
        'content',
        'status',
        'version',
        'confidence',
        'trust',
        'source',
        'created_by_type',
        'created_by_id',
        'approved_by',
        'approved_at',
        'expires_at',
        'last_accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => 'array',
            'confidence' => 'float',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function execution()
    {
        return $this->belongsTo(Execution::class);
    }

    public function versions()
    {
        return $this->hasMany(MemoryVersion::class);
    }

    public function retrievals()
    {
        return $this->hasMany(MemoryRetrieval::class);
    }

    public function approvals()
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * Shared-scope memory: inactive until a memory_review approval is granted
     * (ARCHITECTURE.md MAC-06).
     */
    public function isSharedScope(): bool
    {
        return in_array($this->scope, ['workspace', 'project'], true);
    }
}
