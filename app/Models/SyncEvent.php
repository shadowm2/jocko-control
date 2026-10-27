<?php

namespace App\Models;

use Database\Factories\SyncEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['event_id', 'shop_id', 'device_id', 'event_type', 'aggregate_type', 'aggregate_id', 'base_version', 'payload', 'payload_hash', 'occurred_at', 'received_at'])]
class SyncEvent extends Model
{
    /** @use HasFactory<SyncEventFactory> */
    use HasFactory;

    /**
     * Keep millisecond precision for occurred_at and received_at.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'base_version' => 'integer',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return BelongsTo<ShopDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(ShopDevice::class, 'device_id');
    }
}
