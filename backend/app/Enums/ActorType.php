<?php

namespace App\Enums;

/**
 * Actor identity kinds used across audit_logs, approvals, and memory provenance.
 */
enum ActorType: string
{
    case User = 'user';
    case Agent = 'agent';
    case System = 'system';
}
