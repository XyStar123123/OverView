<?php

namespace App\Enums;

enum ApprovalRequestType: string
{
    case TaskReview = 'task_review';
    case MemoryReview = 'memory_review';
}
