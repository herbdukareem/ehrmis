<?php

namespace App\Domain\ExecutiveOffice;

class OfficeCatalog
{
    public const TYPES = ['correspondence', 'appointment', 'directive', 'meeting', 'visitor', 'briefing'];

    public const ROLES = [
        'executive' => ['read', 'create', 'edit', 'schedule', 'register', 'meetings', 'route', 'decide', 'review', 'respond', 'dispatch', 'notes', 'brief', 'report', 'delegate', 'archive'],
        'lead_pa' => ['read', 'create', 'edit', 'schedule', 'register', 'meetings', 'route', 'review', 'respond', 'dispatch', 'notes', 'brief', 'report', 'archive'],
        'pa' => ['read', 'create', 'edit', 'schedule', 'register', 'meetings', 'route', 'respond', 'dispatch', 'notes', 'brief'],
        'action_officer' => ['read', 'respond'],
        'registry' => ['read', 'register'],
        'administrator' => ['configure'],
    ];

    public const STATES = [
        'correspondence' => ['received', 'draft', 'awaiting_decision', 'assigned', 'awaiting_response', 'response_submitted', 'returned', 'approved', 'dispatched', 'completed', 'rejected'],
        'appointment' => ['requested', 'tentative', 'confirmed', 'completed', 'declined', 'cancelled'],
        'directive' => ['draft', 'awaiting_decision', 'assigned', 'acknowledged', 'in_progress', 'blocked', 'response_submitted', 'returned', 'completed', 'cancelled'],
        'meeting' => ['draft', 'confirmed', 'held', 'minutes_submitted', 'returned', 'approved', 'cancelled'],
        'visitor' => ['expected', 'arrived', 'departed', 'cancelled'],
        'briefing' => ['draft', 'published'],
    ];

    public const CLOSED = ['completed', 'dispatched', 'rejected', 'declined', 'cancelled', 'departed', 'approved', 'published'];

    public const PERMISSIONS = ['view-executive-office', 'manage-executive-office'];

    public const CLASSIFICATIONS = ['standard', 'office_restricted', 'executive_only'];

    public const NOTE_VISIBILITIES = ['office', 'executive_only', 'recipients'];
}
