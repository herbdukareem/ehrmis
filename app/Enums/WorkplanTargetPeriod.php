<?php

namespace App\Enums;

enum WorkplanTargetPeriod: string
{
    case Q1 = 'q1';
    case Q2 = 'q2';
    case Q3 = 'q3';
    case Q4 = 'q4';
    case ANNUAL = 'annual';
}
