<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a shop subscribes to: a price per billing period and a device limit.
 * Plans are informational: Jocko Control shows what a shop is on and whether
 * it is paid up, it never blocks a shop for it.
 */
#[Fillable(['name', 'price', 'billing_days', 'max_devices', 'is_active', 'notes'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'billing_days' => 'integer',
            'max_devices' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Shop, $this>
     */
    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }

    public function hasDeviceLimit(): bool
    {
        return $this->max_devices !== null;
    }
}
