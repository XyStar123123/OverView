<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'default_gateway_id',
        'default_model',
        'default_runtime_id',
        'memory_policy',
    ];

    protected function casts(): array
    {
        return [
            'memory_policy' => 'array',
        ];
    }

    public function members()
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function agents()
    {
        return $this->hasMany(Agent::class);
    }

    public function executions()
    {
        return $this->hasMany(Execution::class);
    }

    public function memories()
    {
        return $this->hasMany(Memory::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Memory policy default per ARCHITECTURE.md 3.1.
     */
    public function memoryPolicy(): array
    {
        return $this->memory_policy ?? ['require_review_for_shared_scopes' => true];
    }
}
