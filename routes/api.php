<?php

use App\Http\Controllers\Api\BudgetWorkbookController;
use App\Http\Controllers\Api\AccessManagementController;
use App\Http\Controllers\Api\CurrentUserContextController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\ExecutiveDashboardController;
use App\Http\Controllers\Api\LegacyStaffImportController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MdaController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\OperationalDataImportController;
use App\Http\Controllers\Api\PromotionWorkflowController;
use App\Http\Controllers\Api\PublicContextController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ServiceReportingController;
use App\Http\Controllers\Api\SetupManagementController;
use App\Http\Controllers\Api\MovementWorkbookController;
use App\Http\Controllers\Api\SpaAuthController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StaffMediaController;
use App\Http\Controllers\Api\StaffPostingRequestController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\WorkflowActionController;
use App\Http\Controllers\Api\WorkplanActivityController;
use App\Http\Controllers\Api\WorkplanController;
use App\Http\Controllers\Api\WorkplanIndicatorController;
use App\Http\Controllers\Api\WorkplanObjectiveController;
use App\Http\Controllers\Api\WorkplanWorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/public-context', [PublicContextController::class, 'show'])->name('api.public-context');
Route::get('/public/promotion/options', [PromotionWorkflowController::class, 'publicOptions'])->name('api.public.promotion.options');
Route::post('/public/promotion/applications', [PromotionWorkflowController::class, 'publicSubmit'])->name('api.public.promotion.applications.store');
Route::post('/login', [SpaAuthController::class, 'login'])->middleware('guest')->name('api.login');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [SpaAuthController::class, 'logout'])->name('api.logout');
    Route::get('/me', [CurrentUserContextController::class, 'show'])->name('api.me');
    Route::get('/modules', [ModuleController::class, 'index'])->name('api.modules.index');
    Route::get('/modules/permissions', [ModuleController::class, 'permissions'])->name('api.modules.permissions');
    Route::get('/modules/role-templates', [ModuleController::class, 'roleTemplates'])->name('api.modules.role-templates.index');
    Route::get('/mdas/{mda}/modules', [ModuleController::class, 'mdaModules'])->name('api.mdas.modules.index');
    Route::put('/mdas/{mda}/modules', [ModuleController::class, 'updateMdaModules'])->name('api.mdas.modules.update');
    Route::middleware('ensure.module:workplan_performance')->group(function (): void {
        Route::get('/workplan-performance/state', [\App\Http\Controllers\Api\StateWorkplanPerformanceController::class, 'show'])->name('api.workplan-performance.state.show');
        Route::get('/workplan-performance/state/rankings', [\App\Http\Controllers\Api\StateWorkplanPerformanceRankingController::class, 'rankings'])->name('api.workplan-performance.state.rankings');
        Route::get('/workplan-performance/state/trends', [\App\Http\Controllers\Api\StateWorkplanPerformanceRankingController::class, 'trends'])->name('api.workplan-performance.state.trends');
        Route::get('/workplan-performance/state/executive', [\App\Http\Controllers\Api\StateExecutivePerformanceController::class, 'show'])->name('api.workplan-performance.state.executive');
        Route::get('/workplans', [WorkplanController::class, 'index'])->name('api.workplans.index');
        Route::post('/workplans', [WorkplanController::class, 'store'])->name('api.workplans.store');
        Route::get('/workplans/{workplan}', [WorkplanController::class, 'show'])->name('api.workplans.show');
        Route::put('/workplans/{workplan}', [WorkplanController::class, 'update'])->name('api.workplans.update');
        Route::post('/workplans/{workplan}/submit', [WorkplanWorkflowController::class, 'submit'])->name('api.workplans.submit');
        Route::post('/workplans/{workplan}/approve', [WorkplanWorkflowController::class, 'approve'])->name('api.workplans.approve');
        Route::post('/workplans/{workplan}/return', [WorkplanWorkflowController::class, 'return'])->name('api.workplans.return');
        Route::post('/workplans/{workplan}/reject', [WorkplanWorkflowController::class, 'reject'])->name('api.workplans.reject');
        Route::post('/workplans/{workplan}/activate', [WorkplanWorkflowController::class, 'activate'])->name('api.workplans.activate');
        Route::post('/workplans/{workplan}/close', [WorkplanWorkflowController::class, 'close'])->name('api.workplans.close');
        Route::post('/workplans/{workplan}/amendments', [WorkplanWorkflowController::class, 'amendment'])->name('api.workplans.amendments.store');
        Route::post('/workplan-activities/{activity}/progress-reports', [\App\Http\Controllers\Api\WorkplanProgressController::class, 'create'])->name('api.workplan-progress.create');
        Route::get('/workplan-progress-reports', [\App\Http\Controllers\Api\WorkplanProgressController::class, 'index'])->name('api.workplan-progress.index');
        Route::get('/workplan-progress-reports/{report}', [\App\Http\Controllers\Api\WorkplanProgressController::class, 'show'])->name('api.workplan-progress.show');
        Route::get('/workplans/{workplan}/progress-matrix', [\App\Http\Controllers\Api\WorkplanProgressController::class, 'matrix'])->name('api.workplan-progress.matrix');
        Route::get('/workplans/{workplan}/performance', [\App\Http\Controllers\Api\WorkplanPerformanceController::class, 'show'])->name('api.workplans.performance.show');
        Route::put('/workplan-progress-reports/{report}', [\App\Http\Controllers\Api\WorkplanProgressController::class, 'update'])->name('api.workplan-progress.update');
        Route::put('/workplan-progress-reports/{report}/indicators', [\App\Http\Controllers\Api\WorkplanProgressController::class, 'indicators'])->name('api.workplan-progress.indicators');
        Route::post('/workplan-progress-reports/{report}/submit', [\App\Http\Controllers\Api\WorkplanProgressController::class, 'submit'])->name('api.workplan-progress.submit');
        Route::post('/workplan-progress-reports/{report}/verify', [\App\Http\Controllers\Api\WorkplanProgressController::class, 'verify'])->name('api.workplan-progress.verify');
        Route::post('/workplan-progress-reports/{report}/return', [\App\Http\Controllers\Api\WorkplanProgressController::class, 'return'])->name('api.workplan-progress.return');
        Route::post('/workplan-progress-reports/{report}/evidence', [\App\Http\Controllers\Api\WorkplanEvidenceController::class, 'store'])->name('api.workplan-evidence.store');
        Route::get('/workplan-evidence/{evidence}/download', [\App\Http\Controllers\Api\WorkplanEvidenceController::class, 'download'])->name('api.workplan-evidence.download');
        Route::delete('/workplan-evidence/{evidence}', [\App\Http\Controllers\Api\WorkplanEvidenceController::class, 'destroy'])->name('api.workplan-evidence.destroy');
        Route::post('/workplans/{workplan}/objectives', [WorkplanObjectiveController::class, 'store'])->name('api.workplans.objectives.store');
        Route::put('/workplan-objectives/{objective}', [WorkplanObjectiveController::class, 'update'])->name('api.workplan-objectives.update');
        Route::delete('/workplan-objectives/{objective}', [WorkplanObjectiveController::class, 'destroy'])->name('api.workplan-objectives.destroy');
        Route::post('/workplan-objectives/{objective}/activities', [WorkplanActivityController::class, 'store'])->name('api.workplan-objectives.activities.store');
        Route::put('/workplan-activities/{activity}', [WorkplanActivityController::class, 'update'])->name('api.workplan-activities.update');
        Route::delete('/workplan-activities/{activity}', [WorkplanActivityController::class, 'destroy'])->name('api.workplan-activities.destroy');
        Route::put('/workplan-activities/{activity}/supporting-staff', [WorkplanActivityController::class, 'syncSupportingStaff'])->name('api.workplan-activities.supporting-staff.update');
        Route::post('/workplan-activities/{activity}/indicators', [WorkplanIndicatorController::class, 'store'])->name('api.workplan-activities.indicators.store');
        Route::put('/workplan-indicators/{indicator}', [WorkplanIndicatorController::class, 'update'])->name('api.workplan-indicators.update');
        Route::delete('/workplan-indicators/{indicator}', [WorkplanIndicatorController::class, 'destroy'])->name('api.workplan-indicators.destroy');
        Route::put('/workplan-indicators/{indicator}/targets', [WorkplanIndicatorController::class, 'syncTargets'])->name('api.workplan-indicators.targets.update');
    });
    Route::middleware('ensure.module:service_reporting')->prefix('service-reports')->group(function (): void {
        Route::get('/', [ServiceReportingController::class, 'dashboard'])->name('api.service-reports.index');
        Route::get('/templates', [ServiceReportingController::class, 'templates'])->name('api.service-reports.templates.index');
        Route::post('/templates', [ServiceReportingController::class, 'storeTemplate'])->name('api.service-reports.templates.store');
        Route::get('/templates/{template}', [ServiceReportingController::class, 'showTemplate'])->name('api.service-reports.templates.show');
        Route::put('/templates/{template}', [ServiceReportingController::class, 'updateTemplate'])->name('api.service-reports.templates.update');
        Route::post('/templates/{template}/activate', [ServiceReportingController::class, 'activateTemplate'])->name('api.service-reports.templates.activate');
        Route::post('/templates/{template}/deactivate', [ServiceReportingController::class, 'deactivateTemplate'])->name('api.service-reports.templates.deactivate');
        Route::post('/templates/{template}/sections', [ServiceReportingController::class, 'storeSection'])->name('api.service-reports.sections.store');
        Route::put('/templates/{template}/sections/{section}', [ServiceReportingController::class, 'updateSection'])->name('api.service-reports.sections.update');
        Route::delete('/templates/{template}/sections/{section}', [ServiceReportingController::class, 'deleteSection'])->name('api.service-reports.sections.destroy');
        Route::post('/templates/{template}/sections/{section}/indicators', [ServiceReportingController::class, 'storeIndicator'])->name('api.service-reports.indicators.store');
        Route::put('/templates/{template}/sections/{section}/indicators/{indicator}', [ServiceReportingController::class, 'updateIndicator'])->name('api.service-reports.indicators.update');
        Route::delete('/templates/{template}/sections/{section}/indicators/{indicator}', [ServiceReportingController::class, 'deleteIndicator'])->name('api.service-reports.indicators.destroy');
        Route::get('/templates/{template}/assignments', [ServiceReportingController::class, 'assignments'])->name('api.service-reports.assignments.index');
        Route::put('/templates/{template}/assignments', [ServiceReportingController::class, 'syncAssignments'])->name('api.service-reports.assignments.update');
        Route::get('/submissions', [ServiceReportingController::class, 'submissions'])->name('api.service-reports.submissions.index');
        Route::post('/submissions', [ServiceReportingController::class, 'storeSubmission'])->name('api.service-reports.submissions.store');
        Route::get('/submissions/{submission}', [ServiceReportingController::class, 'showSubmission'])->name('api.service-reports.submissions.show');
        Route::put('/submissions/{submission}/draft', [ServiceReportingController::class, 'saveDraft'])->name('api.service-reports.submissions.draft');
        Route::post('/submissions/{submission}/submit', [ServiceReportingController::class, 'submit'])->name('api.service-reports.submissions.submit');
        Route::post('/submissions/{submission}/review', [ServiceReportingController::class, 'review'])->name('api.service-reports.submissions.review');
        Route::post('/submissions/{submission}/return', [ServiceReportingController::class, 'returnSubmission'])->name('api.service-reports.submissions.return');
        Route::post('/submissions/{submission}/approve', [ServiceReportingController::class, 'approve'])->name('api.service-reports.submissions.approve');
        Route::post('/submissions/{submission}/lock', [ServiceReportingController::class, 'lock'])->name('api.service-reports.submissions.lock');
        Route::post('/submissions/{submission}/reopen', [ServiceReportingController::class, 'reopen'])->name('api.service-reports.submissions.reopen');
        Route::get('/submissions/{submission}/export', [ServiceReportingController::class, 'exportSubmission'])->name('api.service-reports.submissions.export');
        Route::get('/analytics/indicators', [ServiceReportingController::class, 'indicators'])->name('api.service-reports.analytics.indicators');
        Route::get('/analytics/trends', [ServiceReportingController::class, 'trends'])->name('api.service-reports.analytics.trends');
        Route::get('/analytics/facility-comparison', [ServiceReportingController::class, 'trends'])->name('api.service-reports.analytics.facility-comparison');
        Route::get('/analytics/compliance', [ServiceReportingController::class, 'compliance'])->name('api.service-reports.analytics.compliance');
        Route::get('/analytics/export', [ServiceReportingController::class, 'exportAnalytics'])->name('api.service-reports.analytics.export');
    });
    Route::get('/dashboard', [DashboardController::class, 'show'])
        ->middleware('ensure.module:dashboards_analytics')
        ->name('api.dashboard');
    Route::get('/facility-dashboard', [DashboardController::class, 'facility'])
        ->name('api.facility-dashboard');
    Route::get('/executive-dashboard', ExecutiveDashboardController::class)
        ->middleware('ensure.module:dashboards_analytics')
        ->name('api.executive-dashboard');

    Route::middleware('ensure.module:settings')->group(function (): void {
        Route::get('/settings', [SettingsController::class, 'show'])->name('api.settings.show');
        Route::post('/settings/platform', [SettingsController::class, 'updatePlatform'])->name('api.settings.platform.update');
        Route::post('/settings/mdas/{mda}', [SettingsController::class, 'updateMda'])->name('api.settings.mda.update');
        Route::get('/settings/mdas/{mda}/eligible-heads', [SettingsController::class, 'eligibleHeads'])->name('api.settings.mda.eligible-heads');
        Route::get('/setup-management', [SetupManagementController::class, 'index'])->name('api.setup-management.index');
        Route::post('/setup-management/{type}', [SetupManagementController::class, 'store'])->name('api.setup-management.store');
        Route::put('/setup-management/{type}/{recordId}', [SetupManagementController::class, 'update'])->name('api.setup-management.update');
        Route::delete('/setup-management/{type}/{recordId}', [SetupManagementController::class, 'destroy'])->name('api.setup-management.destroy');
    });

    Route::middleware('ensure.module:access_management')->group(function (): void {
        Route::get('/access-management', [AccessManagementController::class, 'index'])->name('api.access-management.index');
        Route::post('/access-management/users', [AccessManagementController::class, 'storeUser'])->name('api.access-management.users.store');
        Route::post('/access-management/roles', [AccessManagementController::class, 'storeRole'])->name('api.access-management.roles.store');
        Route::put('/access-management/roles/{role}', [AccessManagementController::class, 'updateRole'])->name('api.access-management.roles.update');
        Route::delete('/access-management/roles/{role}', [AccessManagementController::class, 'destroyRole'])->name('api.access-management.roles.destroy');
        Route::put('/access-management/users/{managedUser}', [AccessManagementController::class, 'updateUser'])->name('api.access-management.users.update');
    });

    Route::middleware('ensure.module:staff_registry')->group(function (): void {
        Route::get('/staff/options', [StaffController::class, 'options'])->name('api.staff.options');
        Route::get('/staff/flagged-issues', [StaffController::class, 'flaggedIssues'])->name('api.staff.flagged-issues');
        Route::get('/staff', [StaffController::class, 'index'])->name('api.staff.index');
        Route::get('/staff/{staff}', [StaffController::class, 'show'])->name('api.staff.show');
        Route::get('/staff/{staff}/record-slip', [StaffController::class, 'recordSlip'])->name('api.staff.record-slip.show');
        Route::put('/staff/{staff}', [StaffController::class, 'update'])->name('api.staff.update');
        Route::put('/staff/{staff}/appointment', [StaffController::class, 'updateAppointment'])->name('api.staff.appointment.update');
        Route::put('/staff/{staff}/allowances', [StaffController::class, 'updateAllowances'])->name('api.staff.allowances.update');
        Route::put('/staff/{staff}/flagged-issues', [StaffController::class, 'resolveFlaggedIssue'])->name('api.staff.flagged-issues.resolve');
        Route::post('/staff/{staff}/passport', [StaffMediaController::class, 'storePassport'])->name('api.staff.passport.store');
        Route::get('/staff/{staff}/passport', [StaffMediaController::class, 'passport'])->name('api.staff.passport.show');
        Route::post('/staff/{staff}/documents', [StaffMediaController::class, 'storeDocument'])->name('api.staff.documents.store');
        Route::get('/staff/{staff}/documents/{document}/pages/{page}', [StaffMediaController::class, 'page'])->name('api.staff.documents.pages.show');
        Route::get('/staff/{staff}/documents/{document}/compiled-pdf', [StaffMediaController::class, 'compiledPdf'])->name('api.staff.documents.compiled-pdf.show');
        Route::delete('/staff/{staff}/documents/{document}', [StaffMediaController::class, 'destroy'])->name('api.staff.documents.destroy');
    });

    Route::middleware('ensure.module:legacy_import')->group(function (): void {
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
    });

    Route::middleware('ensure.module:movement_budget')->group(function (): void {
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
    });

    Route::middleware('ensure.module:staff_registry')->group(function (): void {
        Route::get('/promotion-cycles', [PromotionWorkflowController::class, 'index'])->name('api.promotion-cycles.index');
        Route::post('/promotion-cycles', [PromotionWorkflowController::class, 'store'])->name('api.promotion-cycles.store');
        Route::get('/promotion-cycles/{cycle}', [PromotionWorkflowController::class, 'show'])->name('api.promotion-cycles.show');
        Route::post('/promotion-cycles/{cycle}/sittings', [PromotionWorkflowController::class, 'storeSitting'])->name('api.promotion-cycles.sittings.store');
        Route::post('/promotion-applications/{application}/screen', [PromotionWorkflowController::class, 'screen'])->name('api.promotion-applications.screen');
        Route::post('/promotion-applications/{application}/print-letter', [PromotionWorkflowController::class, 'printLetter'])->name('api.promotion-applications.print-letter');
        Route::get('/promotion-applications/{application}/letter-pdf', [PromotionWorkflowController::class, 'letterPdf'])->name('api.promotion-applications.letter-pdf');
        Route::get('/promotion-sittings/{sitting}', [PromotionWorkflowController::class, 'showSitting'])->name('api.promotion-sittings.show');
        Route::post('/promotion-sittings/{sitting}/decisions', [PromotionWorkflowController::class, 'decide'])->name('api.promotion-sittings.decisions.store');
        Route::post('/promotion-sittings/{sitting}/complete', [PromotionWorkflowController::class, 'completeSitting'])->name('api.promotion-sittings.complete');
        Route::post('/promotion-sittings/{sitting}/submit-print-approval', [PromotionWorkflowController::class, 'submitPrintApproval'])->name('api.promotion-sittings.submit-print-approval');
        Route::post('/promotion-sittings/{sitting}/approve-print', [PromotionWorkflowController::class, 'approvePrint'])->name('api.promotion-sittings.approve-print');
        Route::post('/promotion-sittings/{sitting}/reject-print', [PromotionWorkflowController::class, 'rejectPrint'])->name('api.promotion-sittings.reject-print');
    });

    Route::middleware('ensure.module:staff_registry')->group(function (): void {
        Route::get('/posting-requests', [StaffPostingRequestController::class, 'index'])->name('api.posting-requests.index');
        Route::post('/posting-requests', [StaffPostingRequestController::class, 'store'])->name('api.posting-requests.store');
        Route::get('/posting-requests/{postingRequest}', [StaffPostingRequestController::class, 'show'])->name('api.posting-requests.show');
        Route::post('/posting-requests/{postingRequest}/submit', [StaffPostingRequestController::class, 'submit'])->name('api.posting-requests.submit');
        Route::post('/posting-requests/{postingRequest}/approve-origin', [StaffPostingRequestController::class, 'approveOrigin'])->name('api.posting-requests.approve-origin');
        Route::post('/posting-requests/{postingRequest}/approve-receiving', [StaffPostingRequestController::class, 'approveReceiving'])->name('api.posting-requests.approve-receiving');
        Route::post('/posting-requests/{postingRequest}/approve-final', [StaffPostingRequestController::class, 'approveFinal'])->name('api.posting-requests.approve-final');
        Route::post('/posting-requests/{postingRequest}/reject', [StaffPostingRequestController::class, 'reject'])->name('api.posting-requests.reject');
        Route::post('/posting-requests/{postingRequest}/revert', [StaffPostingRequestController::class, 'revert'])->name('api.posting-requests.revert');
        Route::post('/posting-requests/{postingRequest}/issue', [StaffPostingRequestController::class, 'issue'])->name('api.posting-requests.issue');
        Route::post('/posting-requests/{postingRequest}/effect', [StaffPostingRequestController::class, 'effect'])->name('api.posting-requests.effect');
        Route::get('/posting-requests/{postingRequest}/letter-pdf', [StaffPostingRequestController::class, 'letterPdf'])->name('api.posting-requests.letter-pdf');
    });

    Route::middleware('ensure.module:movement_budget')->group(function (): void {
        Route::get('/budget-workbooks', [BudgetWorkbookController::class, 'index'])->name('api.budget-workbooks.index');
        Route::post('/budget-workbooks', [BudgetWorkbookController::class, 'store'])->name('api.budget-workbooks.store');
        Route::get('/budget-workbooks/{budgetWorkbook}', [BudgetWorkbookController::class, 'show'])->name('api.budget-workbooks.show');
        Route::get('/budget-workbooks/{budgetWorkbook}/reports/{report}', [BudgetWorkbookController::class, 'report'])->name('api.budget-workbooks.reports.show');
        Route::post('/budget-workbooks/{budgetWorkbook}/submit', [WorkflowActionController::class, 'budgetSubmit'])->name('api.budget-workbooks.submit');
        Route::post('/budget-workbooks/{budgetWorkbook}/approve', [WorkflowActionController::class, 'budgetApprove'])->name('api.budget-workbooks.approve');
        Route::post('/budget-workbooks/{budgetWorkbook}/reject', [WorkflowActionController::class, 'budgetReject'])->name('api.budget-workbooks.reject');
        Route::post('/budget-workbooks/{budgetWorkbook}/lock', [WorkflowActionController::class, 'budgetLock'])->name('api.budget-workbooks.lock');
        Route::post('/budget-workbooks/{budgetWorkbook}/reopen', [WorkflowActionController::class, 'budgetReopen'])->name('api.budget-workbooks.reopen');
    });

    Route::middleware('ensure.mda')->group(function (): void {
        Route::get('/mdas', [MdaController::class, 'index'])->name('api.mdas.index');
        Route::post('/mdas', [MdaController::class, 'store'])->name('api.mdas.store');
        Route::get('/departments', [DepartmentController::class, 'index'])->name('api.departments.index');
        Route::get('/stations', [StationController::class, 'index'])->name('api.stations.index');
        Route::get('/locations', [LocationController::class, 'index'])->name('api.locations.index');
    });
});
