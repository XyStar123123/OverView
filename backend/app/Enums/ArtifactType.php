<?php

namespace App\Enums;

enum ArtifactType: string
{
    case Code = 'code';
    case Document = 'document';
    case Report = 'report';
    case Image = 'image';
    case Data = 'data';
    case Other = 'other';
}
