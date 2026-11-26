<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Artifact extends Model
{
    protected $fillable = [
        'workspace_id',
        'project_id',
        'task_id',
        'execution_id',
        'agent_id',
        'name',
        'artifact_type',
        'file_path',
        'content_type',
        'size_bytes',
        'version',
        'status',
        'reviewed_by',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_by' => 'integer',
        ];
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function execution()
    {
        return $this->belongsTo(Execution::class);
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }
}
