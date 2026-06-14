<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffUpdateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateStaffQualificationRequest;
use Illuminate\Http\RedirectResponse;

class StaffQualificationController extends Controller
{
    public function store(UpdateStaffQualificationRequest $request, Staff $staff, StaffUpdateService $staffUpdateService): RedirectResponse
    {
        $this->authorize('update', $staff);

        $staffUpdateService->storeQualification($staff, $request->validated());

        return redirect()->route('staff.show', $staff);
    }
}
