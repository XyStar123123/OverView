<?php

namespace App\Enums;

enum ExecutionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** Execution that still holds a task (TASK-015 conflict check). */
    public function inFlight(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Waiting], true);
    }
}
