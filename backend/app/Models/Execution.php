<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Execution extends Model
{
    protected $fillable = [
        'workspace_id',
        'project_id',
        'task_id',
        'agent_id',
        'runtime_id',
        'gateway_id',
        'model',
        'model_resolution',
        'status',
        'started_at',
        'finished_at',
        'usage',
        'failure_reason',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'model_resolution' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'usage' => 'array',
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

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function runtime()
    {
        return $this->belongsTo(Runtime::class);
    }

    public function gateway()
    {
        return $this->belongsTo(Gateway::class);
    }

    public function events()
    {
        return $this->hasMany(ExecutionEvent::class)->orderBy('sequence');
    }

    public function artifacts()
    {
        return $this->hasMany(Artifact::class);
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class);
    }
}
