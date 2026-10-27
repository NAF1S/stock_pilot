<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'organization_id',
    'store_id',
    'traffic_date',
    'page_views',
    'unique_visitors',
    'cart_page_views',
    'checkout_page_views',
    'source',
])]
class TrafficDailyOrg extends \Illuminate\Database\Eloquent\Model
{
    use BelongsToOrganization;

    protected $table = 'sp_traffic_daily_org';

    protected function casts(): array
    {
        return [
            'traffic_date' => 'date',
            'page_views' => 'integer',
            'unique_visitors' => 'integer',
            'cart_page_views' => 'integer',
            'checkout_page_views' => 'integer',
        ];
    }
}
