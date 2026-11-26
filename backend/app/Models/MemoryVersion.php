<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemoryVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'memory_id',
        'version',
        'content',
        'changed_by_type',
        'changed_by_id',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function memory()
    {
        return $this->belongsTo(Memory::class);
    }
}
