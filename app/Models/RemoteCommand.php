<?php

namespace App\Models;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use Database\Factories\RemoteCommandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shop_id', 'device_id', 'command_type', 'payload', 'requested_by', 'status', 'requested_at', 'expires_at', 'delivered_at', 'delivery_count', 'completed_at', 'result'])]
class RemoteCommand extends Model
{
    /** @use HasFactory<RemoteCommandFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'command_type' => CommandType::class,
            'status' => CommandStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isQueued(): bool
    {
        return $this->status === CommandStatus::Queued;
    }

    public function hasPassedExpiry(): bool
    {
        return $this->expires_at->lessThanOrEqualTo(now());
    }
}
