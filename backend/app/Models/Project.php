<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = [
        'workspace_id',
        'slug',
        'name',
        'description',
        'objectives',
        'status',
        'priority',
        'start_date',
        'due_date',
        'owner_user_id',
    ];

    protected function casts(): array
    {
        return [
            'objectives' => 'array',
            'start_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members()
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function agents()
    {
        return $this->belongsToMany(Agent::class, 'project_agents');
    }

    public function executions()
    {
        return $this->hasMany(Execution::class);
    }
}
