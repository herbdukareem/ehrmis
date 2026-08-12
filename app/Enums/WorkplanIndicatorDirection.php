<?php

namespace App\Enums;

enum WorkplanIndicatorDirection: string
{
    case INCREASE = 'increase';
    case DECREASE = 'decrease';
    case MILESTONE = 'milestone';
}
