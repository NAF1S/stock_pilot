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
    'forecast_date',
    'predicted_quantity',
    'lower_bound',
    'upper_bound',
    'model_version',
])]
class DemandForecast extends Model
{
    use BelongsToOrganization;

    protected $table = 'sp_demand_forecasts';

    protected function casts(): array
    {
        return [
            'forecast_date' => 'date',
            'predicted_quantity' => 'float',
            'lower_bound' => 'float',
            'upper_bound' => 'float',
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
