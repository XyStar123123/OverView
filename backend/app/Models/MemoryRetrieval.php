<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemoryRetrieval extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'execution_id',
        'memory_id',
        'rank',
        'score',
        'retrieved_at',
    ];

    protected function casts(): array
    {
        return [
            'retrieved_at' => 'datetime',
        ];
    }

    public function execution()
    {
        return $this->belongsTo(Execution::class);
    }

    public function memory()
    {
        return $this->belongsTo(Memory::class);
    }
}
