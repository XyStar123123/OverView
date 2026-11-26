<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = [
        'workspace_id',
        'project_id',
        'parent_id',
        'title',
        'description',
        'type',
        'type_custom',
        'status',
        'priority',
        'assignee_type',
        'assignee_id',
        'creator_type',
        'creator_id',
        'due_date',
        'labels',
        'override_agent_model',
        'override_gateway_id',
        'override_model',
    ];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'due_date' => 'date',
            'override_agent_model' => 'boolean',
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
    /**
     * Polymorphic-style relation: foreign key belongs to agents or users
     * depending on assignee_type. The related model is resolved at read time;
     * ModelResolver/runner guard on assignee_type before touching it.
     */
    public function assignee()
    {
        $model = $this->assignee_type === 'user' ? User::class : Agent::class;

        return $this->hasOne($model, 'id', 'assignee_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function executions()
    {
        return $this->hasMany(Execution::class);
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class);
    }

    public function artifacts()
    {
        return $this->hasMany(Artifact::class);
    }

    /**
     * PRD 6.3: status transitions allowed by the default lifecycle.
     */
    public function canTransitionTo(string $to): bool
    {
        $from = $this->status;
        $allowed = [
            'todo' => ['in_progress', 'cancelled'],
            'in_progress' => ['pending', 'blocked', 'in_review', 'cancelled'],
            'pending' => ['in_progress', 'cancelled'],
            'blocked' => ['in_progress', 'cancelled'],
            'in_review' => ['done', 'in_progress'],
            'done' => ['in_progress'],
            'cancelled' => ['todo'],
        ];

        return in_array($to, $allowed[$from] ?? [], true);
    }
}
