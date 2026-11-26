<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExecutionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'execution_id',
        'sequence',
        'type',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function execution()
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * Append one event to the execution timeline (sequence = next index,
     * ARCHITECTURE.md 3.6). Named logEvent (not append) to avoid the
     * non-static Eloquent HasAppends::append() collision.
     */
    public static function logEvent(int $executionId, string $type, array $payload = []): self
    {
        $sequence = (int) static::where('execution_id', $executionId)->max('sequence');

        return static::create([
            'execution_id' => $executionId,
            'sequence' => $sequence + 1,
            'type' => $type,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
