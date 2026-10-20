<?php

return [
    'low_budget_percent' => (float) env('PROCUREMENT_LOW_BUDGET_PERCENT', 10),
    'reminder_days' => (int) env('PROCUREMENT_REMINDER_DAYS', 3),
];
