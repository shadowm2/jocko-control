<?php

namespace App\Models;

use App\Enums\BillingStatus;
use App\Enums\ShopStatus;
use Carbon\CarbonInterface;
use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'owner_name', 'email', 'phone', 'address', 'timezone', 'status', 'notes', 'plan_id', 'paid_until', 'keep_original_pictures', 'digest_channels', 'digest_email', 'digest_mobile', 'digest_time'])]
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    public const DIGEST_CHANNELS = ['mail', 'sms', 'internal'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShopStatus::class,
            'restore_allowed_until' => 'datetime',
            'paid_until' => 'date',
            'keep_original_pictures' => 'boolean',
            'digest_channels' => 'array',
            'digest_last_sent_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Whether this shop sends the original of each picture instead of a small preview.
     */
    public function keepsOriginalPictures(): bool
    {
        return (bool) $this->getAttribute('keep_original_pictures');
    }

    /**
     * The ways the daily report reaches people: `mail` and `sms` go to the shop
     * (its owner), `internal` to the administrators' notification inbox here.
     *
     * @return list<string>
     */
    public function digestChannels(): array
    {
        $channels = $this->getAttribute('digest_channels');

        return is_array($channels) ? array_values(array_intersect(self::DIGEST_CHANNELS, $channels)) : [];
    }

    public function digestEmail(): ?string
    {
        return filled($this->getAttribute('digest_email')) ? (string) $this->getAttribute('digest_email') : (filled($this->email) ? (string) $this->email : null);
    }

    public function digestMobile(): ?string
    {
        return filled($this->getAttribute('digest_mobile')) ? (string) $this->getAttribute('digest_mobile') : (filled($this->phone) ? (string) $this->phone : null);
    }

    public function paidUntil(): ?CarbonInterface
    {
        $until = $this->getAttribute('paid_until');

        return $until instanceof CarbonInterface ? $until : null;
    }

    /**
     * Where the shop stands with its plan. The paid-until day itself still counts as paid.
     */
    public function billingStatus(): BillingStatus
    {
        $until = $this->paidUntil();

        if ($this->plan_id === null || $until === null) {
            return BillingStatus::NoPlan;
        }

        if ($until->copy()->endOfDay()->isPast()) {
            return BillingStatus::Expired;
        }

        return $until->copy()->startOfDay()->lte(now()->addDays(BillingStatus::DUE_SOON_DAYS)->endOfDay()) ? BillingStatus::DueSoon : BillingStatus::Paid;
    }

    /**
     * @return HasMany<SubscriptionPayment, $this>
     */
    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
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
