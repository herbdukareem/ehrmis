<?php

namespace App\Enums;

enum WorkplanTargetMode: string
{
    case ABSOLUTE = 'absolute';
    case INCREASE_FROM_BASELINE = 'increase_from_baseline';
    case DECREASE_FROM_BASELINE = 'decrease_from_baseline';
    case MILESTONE = 'milestone';
}
