<?php

namespace App\Models\Scopes;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra toda consulta por la organización (consultorio) vigente.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $id = Organization::currentId();

        if ($id !== null) {
            $builder->where($model->qualifyColumn('organization_id'), $id);
        }
    }
}
