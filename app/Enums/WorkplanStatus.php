<?php

namespace App\Enums;

enum WorkplanStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case UNDER_REVIEW = 'under_review';
    case RETURNED = 'returned';
    case REJECTED = 'rejected';
    case APPROVED = 'approved';
    case ACTIVE = 'active';
    case CLOSED = 'closed';
    case SUPERSEDED = 'superseded';
}
