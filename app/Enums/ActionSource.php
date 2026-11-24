<?php

namespace App\Enums;

enum ActionSource: string
{
    case Manual = 'manual';
    case Ai = 'ai';
    case Import = 'import';
}
