<?php

namespace App\Enums;

enum WorkplanProgressStatus: string { case DRAFT = 'draft'; case SUBMITTED = 'submitted'; case RETURNED = 'returned'; case VERIFIED = 'verified'; }
