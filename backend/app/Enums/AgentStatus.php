<?php

namespace App\Enums;

enum AgentStatus: string
{
    case Draft = 'draft';
    case Available = 'available';
    case Working = 'working';
    case Blocked = 'blocked';
    case WaitingForApproval = 'waiting_for_approval';
    case Offline = 'offline';
    case Disabled = 'disabled';
    case Error = 'error';

    /** Statuses that may be assigned to a task and executed. */
    public function active(): bool
    {
        return in_array($this, [self::Available, self::Working, self::Blocked, self::WaitingForApproval], true);
    }
}
