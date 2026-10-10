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
    'recommended_reorder_qty',
    'urgency',
    'reasoning',
    'status',
    'model_version',
])]
class RestockRecommendation extends Model
{
    use BelongsToOrganization;

    protected $table = 'sp_restock_recommendations';

    protected function casts(): array
    {
        return [
            'recommended_reorder_qty' => 'float',
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
