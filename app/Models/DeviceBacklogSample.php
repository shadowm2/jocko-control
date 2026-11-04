<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A device's unsent changes at one moment, taken from a heartbeat.
 *
 * @property int $id
 * @property string $device_id
 * @property int $pending_events
 * @property ?int $oldest_pending_seconds
 * @property Carbon $sampled_at
 */
#[Fillable(['device_id', 'pending_events', 'oldest_pending_seconds', 'sampled_at'])]
class DeviceBacklogSample extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sampled_at' => 'datetime'];
    }
}
