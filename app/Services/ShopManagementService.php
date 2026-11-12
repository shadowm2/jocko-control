<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ShopStatus;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ShopManagementService
{
    /** How long the shop's device may read its data back once an administrator allows it. */
    private const RESTORE_WINDOW_HOURS = 24;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $admin): Shop
    {
        return DB::transaction(function () use ($data, $admin): Shop {
            $shop = Shop::create($data + ['status' => ShopStatus::Active]);

            $this->audit->record(AuditAction::ShopCreated, $admin, $shop, metadata: ['name' => $shop->name, 'slug' => $shop->slug]);

            return $shop;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Shop $shop, array $data, User $admin): Shop
    {
        return DB::transaction(function () use ($shop, $data, $admin): Shop {
            $shop->fill($data);
            $changed = array_keys($shop->getDirty());

            if ($changed !== []) {
                $shop->save();
                $this->audit->record(AuditAction::ShopUpdated, $admin, $shop, metadata: ['changed' => $changed]);
            }

            return $shop;
        });
    }

    public function suspend(Shop $shop, User $admin): Shop
    {
        return $this->changeStatus($shop, ShopStatus::Suspended, AuditAction::ShopSuspended, $admin);
    }

    public function reactivate(Shop $shop, User $admin): Shop
    {
        return $this->changeStatus($shop, ShopStatus::Active, AuditAction::ShopReactivated, $admin);
    }

    /**
     * Open a window in which this shop's device may read its mirrored data
     * back to rebuild a lost installation.
     */
    public function allowRestore(Shop $shop, User $admin): Shop
    {
        $shop->forceFill(['restore_allowed_until' => now()->addHours(self::RESTORE_WINDOW_HOURS)])->save();
        $this->audit->record(AuditAction::ShopRestoreAllowed, $admin, $shop, metadata: ['hours' => self::RESTORE_WINDOW_HOURS]);

        return $shop;
    }

    /**
     * Close the restore window early: the shop's device is refused again.
     */
    public function revokeRestore(Shop $shop, User $admin): Shop
    {
        if ($shop->restoreAllowed()) {
            $shop->forceFill(['restore_allowed_until' => null])->save();
            $this->audit->record(AuditAction::ShopRestoreRevoked, $admin, $shop);
        }

        return $shop;
    }

    private function changeStatus(Shop $shop, ShopStatus $status, AuditAction $action, User $admin): Shop
    {
        return DB::transaction(function () use ($shop, $status, $action, $admin): Shop {
            $shop = Shop::query()->lockForUpdate()->findOrFail($shop->getKey());

            if ($shop->status !== $status) {
                $shop->update(['status' => $status]);
                $this->audit->record($action, $admin, $shop);
            }

            return $shop;
        });
    }
}
