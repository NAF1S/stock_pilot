<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'organization_id',
    'store_id',
    'product_id',
    'bucket_hour',
    'traffic_date',
    'page_views',
    'add_to_cart',
    'cart_removals',
    'sessions',
    'unique_visitors',
    'source',
])]
class TrafficHourly extends \Illuminate\Database\Eloquent\Model
{
    use BelongsToOrganization;

    protected $table = 'sp_traffic_hourly';

    protected function casts(): array
    {
        return [
            'bucket_hour' => 'datetime',
            'traffic_date' => 'date',
            'page_views' => 'integer',
            'add_to_cart' => 'integer',
            'cart_removals' => 'integer',
            'sessions' => 'integer',
            'unique_visitors' => 'integer',
        ];
    }
}
