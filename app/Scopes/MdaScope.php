<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class MdaScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user || ! method_exists($user, 'hasGlobalMdaAccess') || $user->hasGlobalMdaAccess()) {
            return;
        }

        if (! $user->hasAnyMdaAccess()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $column = method_exists($model, 'getMdaScopeColumn')
            ? $model->getMdaScopeColumn()
            : 'mda_id';

        $user->scopeToAccessibleMdas($builder, $model->qualifyColumn($column));
    }
}
