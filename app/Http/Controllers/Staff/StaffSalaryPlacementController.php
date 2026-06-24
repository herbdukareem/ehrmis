<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffSalaryPlacementService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateStaffSalaryPlacementRequest;
use Illuminate\Http\RedirectResponse;

class StaffSalaryPlacementController extends Controller
{
    public function store(UpdateStaffSalaryPlacementRequest $request, Staff $staff, StaffSalaryPlacementService $staffSalaryPlacementService): RedirectResponse
    {
        $this->authorize('updateAppointment', $staff);

        $validated = $request->validated();
        $validated['salary_scale'] = SalaryScale::query()
            ->forMda((int) $staff->mda_id)
            ->findOrFail((int) $validated['salary_scale_id']);

        $staffSalaryPlacementService->createPlacement($staff, $validated);

        return redirect()->route('staff.show', $staff);
    }
}
