<?php

namespace App\Enums;

enum ArtifactStatus: string
{
    case Submitted = 'submitted';
    case Reviewed = 'reviewed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
