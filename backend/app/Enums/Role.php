<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    /** Roles that may mutate shared resources; viewer is read-only (9.1). */
    public function canMutate(): bool
    {
        return $this !== self::Viewer;
    }
}
