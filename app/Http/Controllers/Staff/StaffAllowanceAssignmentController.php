<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffAllowanceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateStaffAllowanceAssignmentRequest;
use Illuminate\Http\RedirectResponse;

class StaffAllowanceAssignmentController extends Controller
{
    public function sync(UpdateStaffAllowanceAssignmentRequest $request, Staff $staff, StaffAllowanceService $staffAllowanceService): RedirectResponse
    {
        $this->authorize('update', $staff);

        $staffAllowanceService->syncAssignments($staff, $request->validated()['assignments']);

        return redirect()->route('staff.show', $staff);
    }
}
