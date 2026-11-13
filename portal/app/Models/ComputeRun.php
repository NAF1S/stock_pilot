<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'status',
    'trigger_source',
    'total_entities',
    'done_entities',
    'progress_pct',
    'run_id',
    'error',
    'triggered_by',
    'started_at',
    'finished_at',
])]
class ComputeRun extends Model
{
    use BelongsToOrganization;

    protected $table = 'sp_compute_runs';

    protected function casts(): array
    {
        return [
            'total_entities' => 'integer',
            'done_entities' => 'integer',
            'progress_pct' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
