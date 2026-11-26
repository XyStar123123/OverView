<?php

namespace App\Enums;

/**
 * Memory lifecycle states (ARCHITECTURE.md 3.8).
 */
enum MemoryStatus: string
{
    case Candidate = 'candidate';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Active = 'active';
    case Superseded = 'superseded';
    case Archived = 'archived';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function terminal(): bool
    {
        return in_array($this, [self::Superseded, self::Archived, self::Rejected, self::Expired], true);
    }
}
