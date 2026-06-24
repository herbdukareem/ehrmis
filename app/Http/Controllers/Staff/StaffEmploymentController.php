<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffUpdateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateStaffEmploymentRequest;
use Illuminate\Http\RedirectResponse;

class StaffEmploymentController extends Controller
{
    public function store(UpdateStaffEmploymentRequest $request, Staff $staff, StaffUpdateService $staffUpdateService): RedirectResponse
    {
        $this->authorize('updateAppointment', $staff);

        $staffUpdateService->createEmploymentRecord($staff, $request->validated());

        return redirect()->route('staff.show', $staff);
    }
}
