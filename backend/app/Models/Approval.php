<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'request_type',
        'task_id',
        'memory_id',
        'execution_id',
        'status',
        'requested_by_type',
        'requested_by_id',
        'decision_user_id',
        'decision_reason',
        'decided_at',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function memory()
    {
        return $this->belongsTo(Memory::class);
    }

    public function execution()
    {
        return $this->belongsTo(Execution::class);
    }

    public function decisionUser()
    {
        return $this->belongsTo(User::class, 'decision_user_id');
    }
}
