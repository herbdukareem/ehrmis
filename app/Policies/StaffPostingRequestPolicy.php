<?php

namespace App\Policies;

use App\Domain\Posting\Models\StaffPostingRequest;
use App\Models\User;

class StaffPostingRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-postings');
    }

    public function view(User $user, StaffPostingRequest $request): bool
    {
        return $user->can('view-postings') && $this->canAccessEitherMda($user, $request);
    }

    public function create(User $user): bool
    {
        return $user->can('create-postings');
    }

    public function submit(User $user, StaffPostingRequest $request): bool
    {
        return $user->can('create-postings') && $user->canAccessMda($request->from_mda_id);
    }

    public function approveOrigin(User $user, StaffPostingRequest $request): bool
    {
        return $user->can('approve-own-mda-postings') && $user->canAccessMda($request->from_mda_id);
    }

    public function approveReceiving(User $user, StaffPostingRequest $request): bool
    {
        return $user->can('approve-receiving-mda-postings') && $user->canAccessMda($request->to_mda_id);
    }

    public function approveFinal(User $user, StaffPostingRequest $request): bool
    {
        return $user->can('approve-inter-mda-postings') && $this->canAccessEitherMda($user, $request);
    }

    public function reject(User $user, StaffPostingRequest $request): bool
    {
        return ($user->can('approve-own-mda-postings') && $user->canAccessMda($request->from_mda_id))
            || ($user->can('approve-receiving-mda-postings') && $user->canAccessMda($request->to_mda_id))
            || ($user->can('approve-inter-mda-postings') && $this->canAccessEitherMda($user, $request));
    }

    public function issue(User $user, StaffPostingRequest $request): bool
    {
        return $user->can('print-posting-letters') && $this->canAccessEitherMda($user, $request);
    }

    public function effect(User $user, StaffPostingRequest $request): bool
    {
        return $user->can('effect-postings') && $this->canAccessEitherMda($user, $request);
    }

    protected function canAccessEitherMda(User $user, StaffPostingRequest $request): bool
    {
        return $user->canAccessMda($request->from_mda_id) || $user->canAccessMda($request->to_mda_id);
    }
}
