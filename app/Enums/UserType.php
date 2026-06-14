<?php

namespace App\Enums;

enum UserType: string
{
    case SUPER_ADMIN = 'super_admin';
    case MIS_ADMIN = 'mis_admin';
    case MDA_ADMIN = 'mda_admin';
    case HR_OFFICER = 'hr_officer';
    case BUDGET_OFFICER = 'budget_officer';
    case PAYROLL_AUDITOR = 'payroll_auditor';
    case REPORT_VIEWER = 'report_viewer';
    case APPROVAL_OFFICER = 'approval_officer';
}
