<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Pending = 'pending';
    case Blocked = 'blocked';
    case InReview = 'in_review';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /**
     * Runner pre-condition: a task can only be executed from these states
     * (ARCHITECTURE.md 5.1).
     */
    public function executable(): bool
    {
        return in_array($this, [self::Todo, self::InProgress], true);
    }
}
