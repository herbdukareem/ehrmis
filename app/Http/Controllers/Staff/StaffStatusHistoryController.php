<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffUpdateService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StaffStatusHistoryController extends Controller
{
    public function store(Request $request, Staff $staff, StaffUpdateService $staffUpdateService): RedirectResponse
    {
        $this->authorize('update', $staff);

        $validated = $request->validate([
            'status' => ['required', 'string', 'max:30'],
            'reason' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['nullable', 'date'],
        ]);

        $staffUpdateService->storeStatusHistory($staff, $validated);

        return redirect()->route('staff.show', $staff);
    }
}
