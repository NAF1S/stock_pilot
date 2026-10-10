<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'entity_type',
    'entity_id',
    'current_stock',
    'daily_burn_rate',
    'days_until_stockout',
    'risk_level',
    'estimated_stockout_date',
    'model_version',
    'calculated_at',
])]
class StockoutRisk extends Model
{
    use BelongsToOrganization;

    protected $table = 'sp_stockout_risks';

    protected function casts(): array
    {
        return [
            'current_stock' => 'float',
            'daily_burn_rate' => 'float',
            'days_until_stockout' => 'float',
            'estimated_stockout_date' => 'date',
            'calculated_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'entity_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'entity_id');
    }
}
