<?php

use App\Http\Controllers\Api\BudgetWorkbookController;
use App\Http\Controllers\Api\AccessManagementController;
use App\Http\Controllers\Api\CurrentUserContextController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\LegacyStaffImportController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MdaController;
use App\Http\Controllers\Api\OperationalDataImportController;
use App\Http\Controllers\Api\PublicContextController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\MovementWorkbookController;
use App\Http\Controllers\Api\SpaAuthController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StaffMediaController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\WorkflowActionController;
use Illuminate\Support\Facades\Route;

Route::get('/public-context', [PublicContextController::class, 'show'])->name('api.public-context');
Route::post('/login', [SpaAuthController::class, 'login'])->middleware('guest')->name('api.login');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [SpaAuthController::class, 'logout'])->name('api.logout');
    Route::get('/me', [CurrentUserContextController::class, 'show'])->name('api.me');
    Route::get('/dashboard', [DashboardController::class, 'show'])->name('api.dashboard');
    Route::get('/settings', [SettingsController::class, 'show'])->name('api.settings.show');
    Route::post('/settings/platform', [SettingsController::class, 'updatePlatform'])->name('api.settings.platform.update');
    Route::post('/settings/mdas/{mda}', [SettingsController::class, 'updateMda'])->name('api.settings.mda.update');
    Route::get('/settings/mdas/{mda}/eligible-heads', [SettingsController::class, 'eligibleHeads'])->name('api.settings.mda.eligible-heads');
    Route::get('/access-management', [AccessManagementController::class, 'index'])->name('api.access-management.index');
    Route::put('/access-management/roles/{role}', [AccessManagementController::class, 'updateRole'])->name('api.access-management.roles.update');
    Route::put('/access-management/users/{managedUser}', [AccessManagementController::class, 'updateUser'])->name('api.access-management.users.update');

    Route::get('/staff/options', [StaffController::class, 'options'])->name('api.staff.options');
    Route::get('/staff/flagged-issues', [StaffController::class, 'flaggedIssues'])->name('api.staff.flagged-issues');
    Route::get('/staff', [StaffController::class, 'index'])->name('api.staff.index');
    Route::get('/staff/{staff}', [StaffController::class, 'show'])->name('api.staff.show');
    Route::put('/staff/{staff}', [StaffController::class, 'update'])->name('api.staff.update');
    Route::put('/staff/{staff}/allowances', [StaffController::class, 'updateAllowances'])->name('api.staff.allowances.update');
    Route::put('/staff/{staff}/flagged-issues', [StaffController::class, 'resolveFlaggedIssue'])->name('api.staff.flagged-issues.resolve');
    Route::post('/staff/{staff}/passport', [StaffMediaController::class, 'storePassport'])->name('api.staff.passport.store');
    Route::get('/staff/{staff}/passport', [StaffMediaController::class, 'passport'])->name('api.staff.passport.show');
    Route::post('/staff/{staff}/documents', [StaffMediaController::class, 'storeDocument'])->name('api.staff.documents.store');
    Route::get('/staff/{staff}/documents/{document}/pages/{page}', [StaffMediaController::class, 'page'])->name('api.staff.documents.pages.show');
    Route::get('/staff/{staff}/documents/{document}/compiled-pdf', [StaffMediaController::class, 'compiledPdf'])->name('api.staff.documents.compiled-pdf.show');
    Route::delete('/staff/{staff}/documents/{document}', [StaffMediaController::class, 'destroy'])->name('api.staff.documents.destroy');

    Route::get('/legacy-staff-imports', [LegacyStaffImportController::class, 'index'])->name('api.legacy-staff-imports.index');
    Route::post('/operational-imports/{type}', [OperationalDataImportController::class, 'store'])->name('api.operational-imports.store');
    Route::get('/operational-imports/{type}/template', [OperationalDataImportController::class, 'template'])->name('api.operational-imports.template');
    Route::get('/legacy-staff-imports/{batch}', [LegacyStaffImportController::class, 'show'])->name('api.legacy-staff-imports.show');
    Route::get('/legacy-staff-imports/{batch}/rows/{row}', [LegacyStaffImportController::class, 'showRow'])->name('api.legacy-staff-imports.rows.show');
    Route::post('/legacy-staff-imports/{batch}/rows/{row}/ignore-warning', [LegacyStaffImportController::class, 'ignoreWarning'])->name('api.legacy-staff-imports.rows.ignore-warning');
    Route::post('/legacy-staff-imports/{batch}/rows/{row}/resolve-mapping', [LegacyStaffImportController::class, 'resolveMapping'])->name('api.legacy-staff-imports.rows.resolve-mapping');
    Route::post('/legacy-staff-imports/{batch}/rows/{row}/resolve-identifier', [LegacyStaffImportController::class, 'resolveIdentifier'])->name('api.legacy-staff-imports.rows.resolve-identifier');
    Route::post('/legacy-staff-imports/{batch}/rows/{row}/publish', [LegacyStaffImportController::class, 'publishRow'])->name('api.legacy-staff-imports.rows.publish');
    Route::post('/legacy-staff-imports/{batch}/submit', [WorkflowActionController::class, 'importSubmit'])->name('api.legacy-staff-imports.submit');
    Route::post('/legacy-staff-imports/{batch}/approve', [WorkflowActionController::class, 'importApprove'])->name('api.legacy-staff-imports.approve');
    Route::post('/legacy-staff-imports/{batch}/reject', [WorkflowActionController::class, 'importReject'])->name('api.legacy-staff-imports.reject');
    Route::post('/legacy-staff-imports/{batch}/publish', [WorkflowActionController::class, 'importPublish'])->name('api.legacy-staff-imports.publish');

    Route::get('/movement-workbooks', [MovementWorkbookController::class, 'index'])->name('api.movement-workbooks.index');
    Route::post('/movement-workbooks', [MovementWorkbookController::class, 'store'])->name('api.movement-workbooks.store');
    Route::get('/movement-workbooks/{workbook}', [MovementWorkbookController::class, 'show'])->name('api.movement-workbooks.show');
    Route::get('/movement-workbooks/{workbook}/summary-export', [MovementWorkbookController::class, 'exportSummary'])->name('api.movement-workbooks.summary-export');
    Route::get('/movement-workbooks/{workbook}/detail-export', [MovementWorkbookController::class, 'exportDetail'])->name('api.movement-workbooks.detail-export');
    Route::post('/movement-workbooks/{workbook}/review', [WorkflowActionController::class, 'movementReview'])->name('api.movement-workbooks.review');
    Route::post('/movement-workbooks/{workbook}/approve', [WorkflowActionController::class, 'movementApprove'])->name('api.movement-workbooks.approve');
    Route::post('/movement-workbooks/{workbook}/reject', [WorkflowActionController::class, 'movementReject'])->name('api.movement-workbooks.reject');
    Route::post('/movement-workbooks/{workbook}/lock', [WorkflowActionController::class, 'movementLock'])->name('api.movement-workbooks.lock');
    Route::post('/movement-workbooks/{workbook}/reopen', [WorkflowActionController::class, 'movementReopen'])->name('api.movement-workbooks.reopen');

    Route::get('/budget-workbooks', [BudgetWorkbookController::class, 'index'])->name('api.budget-workbooks.index');
    Route::post('/budget-workbooks', [BudgetWorkbookController::class, 'store'])->name('api.budget-workbooks.store');
    Route::get('/budget-workbooks/{budgetWorkbook}', [BudgetWorkbookController::class, 'show'])->name('api.budget-workbooks.show');
    Route::post('/budget-workbooks/{budgetWorkbook}/submit', [WorkflowActionController::class, 'budgetSubmit'])->name('api.budget-workbooks.submit');
    Route::post('/budget-workbooks/{budgetWorkbook}/approve', [WorkflowActionController::class, 'budgetApprove'])->name('api.budget-workbooks.approve');
    Route::post('/budget-workbooks/{budgetWorkbook}/reject', [WorkflowActionController::class, 'budgetReject'])->name('api.budget-workbooks.reject');
    Route::post('/budget-workbooks/{budgetWorkbook}/lock', [WorkflowActionController::class, 'budgetLock'])->name('api.budget-workbooks.lock');
    Route::post('/budget-workbooks/{budgetWorkbook}/reopen', [WorkflowActionController::class, 'budgetReopen'])->name('api.budget-workbooks.reopen');

    Route::middleware('ensure.mda')->group(function (): void {
        Route::get('/mdas', [MdaController::class, 'index'])->name('api.mdas.index');
        Route::get('/departments', [DepartmentController::class, 'index'])->name('api.departments.index');
        Route::get('/stations', [StationController::class, 'index'])->name('api.stations.index');
        Route::get('/locations', [LocationController::class, 'index'])->name('api.locations.index');
    });
});
