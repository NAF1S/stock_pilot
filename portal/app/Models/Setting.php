<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Key/value settings per organization. Composite key (organization_id, key).
 * Always query via ->where('organization_id', ...)->where('key', ...); the
 * model intentionally has no id primary key.
 */
#[Fillable(['organization_id', 'key', 'value'])]
class Setting extends \Illuminate\Database\Eloquent\Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'sp_settings';

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Set the keys for a save / update / delete query on composite primary key.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function setKeysForSaveQuery($query)
    {
        $query->where('organization_id', '=', $this->getAttribute('organization_id'))
            ->where('key', '=', $this->getAttribute('key'));

        return $query;
    }
}
