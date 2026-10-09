<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'store_id',
    'woocommerce_order_id',
    'event_id',
    'nonce',
    'signature',
    'event_type',
    'wc_status',
    'payload',
    'status',
    'processing_error',
    'processed_at',
])]
class WebhookEvent extends \Illuminate\Database\Eloquent\Model
{
    use BelongsToOrganization;

    protected $table = 'sp_webhook_events';

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(StoreConnection::class, 'store_id');
    }
}
