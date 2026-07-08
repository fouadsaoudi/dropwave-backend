<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->tenant_id) && request()->user()) {
                $model->tenant_id = request()->user()->tenant_id;
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            $user = request()->user();
            if ($user && $user->tenant_id) {
                $builder->where($builder->getQuery()->from . '.tenant_id', $user->tenant_id);
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
