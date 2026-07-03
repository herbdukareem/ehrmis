<?php

namespace App\Providers;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Posting\Models\StaffPostingRequest;
use App\Domain\Promotion\Models\PromotionApplication;
use App\Domain\Promotion\Models\PromotionCycle;
use App\Domain\Promotion\Models\PromotionSitting;
use App\Domain\Staff\Models\Staff;
use App\Domain\ServiceReporting\Models\ReportSubmission;
use App\Domain\ServiceReporting\Models\ReportTemplate;
use App\Models\Role;
use App\Models\User;
use App\Policies\BudgetWorkbookPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\LegacyStaffImportPolicy;
use App\Policies\LegacyStaffImportRowPolicy;
use App\Policies\LocationPolicy;
use App\Policies\MdaPolicy;
use App\Policies\MovementWorkbookPolicy;
use App\Policies\PromotionApplicationPolicy;
use App\Policies\PromotionCyclePolicy;
use App\Policies\PromotionSittingPolicy;
use App\Policies\RolePolicy;
use App\Policies\ReportSubmissionPolicy;
use App\Policies\ReportTemplatePolicy;
use App\Policies\StaffPostingRequestPolicy;
use App\Policies\StaffPolicy;
use App\Policies\StationPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use App\Support\DomainContext;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(DomainContext::class, fn () => new DomainContext());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::policy(Mda::class, MdaPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Station::class, StationPolicy::class);
        Gate::policy(Location::class, LocationPolicy::class);
        Gate::policy(Staff::class, StaffPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(LegacyStaffImportBatch::class, LegacyStaffImportPolicy::class);
        Gate::policy(LegacyStaffImportRow::class, LegacyStaffImportRowPolicy::class);
        Gate::policy(MovementWorkbook::class, MovementWorkbookPolicy::class);
        Gate::policy(BudgetWorkbook::class, BudgetWorkbookPolicy::class);
        Gate::policy(PromotionCycle::class, PromotionCyclePolicy::class);
        Gate::policy(PromotionApplication::class, PromotionApplicationPolicy::class);
        Gate::policy(PromotionSitting::class, PromotionSittingPolicy::class);
        Gate::policy(StaffPostingRequest::class, StaffPostingRequestPolicy::class);
        Gate::policy(ReportTemplate::class, ReportTemplatePolicy::class);
        Gate::policy(ReportSubmission::class, ReportSubmissionPolicy::class);

        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            $event->user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => request()?->ip(),
            ])->save();
        });
    }
}
