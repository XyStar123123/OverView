<?php

namespace App\Enums;

enum TaskType: string
{
    case Feature = 'feature';
    case Bug = 'bug';
    case Fix = 'fix';
    case Research = 'research';
    case Documentation = 'documentation';
    case Maintenance = 'maintenance';
    case Investigation = 'investigation';
    case Custom = 'custom';
}
