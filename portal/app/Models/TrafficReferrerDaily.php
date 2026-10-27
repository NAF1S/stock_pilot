<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'organization_id',
    'store_id',
    'traffic_date',
    'referrer_group',
    'page_views',
    'source',
])]
class TrafficReferrerDaily extends \Illuminate\Database\Eloquent\Model
{
    use BelongsToOrganization;

    protected $table = 'sp_traffic_referrers_daily';

    protected function casts(): array
    {
        return [
            'traffic_date' => 'date',
            'page_views' => 'integer',
        ];
    }
}
