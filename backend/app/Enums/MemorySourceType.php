<?php

namespace App\Enums;

/**
 * Provenance source types (memories.source JSON, ARCHITECTURE.md 3.8 / PRD 6.16.9).
 */
enum MemorySourceType: string
{
    case Execution = 'execution';
    case Task = 'task';
    case User = 'user';
    case Document = 'document';
    case Artifact = 'artifact';
}
