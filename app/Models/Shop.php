<?php

namespace App\Models;

use App\Enums\ShopStatus;
use Carbon\CarbonInterface;
use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'owner_name', 'email', 'phone', 'address', 'timezone', 'status', 'notes'])]
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShopStatus::class,
            'restore_allowed_until' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ShopDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(ShopDevice::class);
    }

    /**
     * @return HasMany<SyncEvent, $this>
     */
    public function syncEvents(): HasMany
    {
        return $this->hasMany(SyncEvent::class);
    }

    /**
     * @return HasMany<RemoteCommand, $this>
     */
    public function remoteCommands(): HasMany
    {
        return $this->hasMany(RemoteCommand::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Whether an administrator has opened the window in which the shop's device
     * may read its data back.
     */
    public function restoreAllowed(): bool
    {
        return $this->restoreAllowedUntil()?->isFuture() ?? false;
    }

    public function restoreAllowedUntil(): ?CarbonInterface
    {
        $until = $this->getAttribute('restore_allowed_until');

        return $until instanceof CarbonInterface ? $until : null;
    }

    public function isActive(): bool
    {
        return $this->status === ShopStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === ShopStatus::Suspended;
    }

    /**
     * @param  Builder<Shop>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '\\%_').'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->where('name', 'like', $like)->orWhere('slug', 'like', $like);
        });
    }
}
