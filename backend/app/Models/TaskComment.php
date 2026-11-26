<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskComment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'actor_type',
        'actor_id',
        'body',
        'execution_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
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
}
