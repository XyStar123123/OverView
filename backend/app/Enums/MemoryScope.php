<?php

namespace App\Enums;

enum MemoryScope: string
{
    case Workspace = 'workspace';
    case Project = 'project';
    case Agent = 'agent';
    case Task = 'task';
}
