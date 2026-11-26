<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gateway extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'type',
        'base_url',
        'api_key_ref',
        'api_format',
        'status',
        'models',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'models' => 'array',
            'last_checked_at' => 'datetime',
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
