<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Runtime extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'adapter',
        'status',
        'capabilities',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
        ];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function agents()
    {
        return $this->hasMany(Agent::class);
    }
}
