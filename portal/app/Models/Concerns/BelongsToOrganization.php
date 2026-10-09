<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            if (Auth::check() && ($orgId = Auth::user()?->organization_id)) {
                $builder->where($builder->getModel()->getTable().'.organization_id', $orgId);
            }
        });

        static::creating(function ($model) {
            if (empty($model->organization_id) && Auth::check() && ($orgId = Auth::user()?->organization_id)) {
                $model->organization_id = $orgId;
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
