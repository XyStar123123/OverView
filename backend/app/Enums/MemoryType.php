<?php

namespace App\Enums;

enum MemoryType: string
{
    case Working = 'working';
    case Episodic = 'episodic';
    case Semantic = 'semantic';
    case Procedural = 'procedural';
}
