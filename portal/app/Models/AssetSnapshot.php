<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'captured_at', 'total_value', 'per_category'])]
class AssetSnapshot extends \Illuminate\Database\Eloquent\Model
{
    use BelongsToOrganization;

    public const CREATED_AT = 'captured_at';

    public const UPDATED_AT = null;

    protected $table = 'sp_asset_snapshots';

    protected function casts(): array
    {
        return [
            'total_value' => 'float',
            'per_category' => 'array',
            'captured_at' => 'datetime',
        ];
    }
}
