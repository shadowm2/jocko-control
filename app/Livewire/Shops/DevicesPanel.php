<?php

namespace App\Livewire\Shops;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\AuditLog;
use App\Models\RemoteCommand;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\SyncEvent;
use App\Services\DeviceManagementService;
use App\Services\FleetVersions;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class DevicesPanel extends Component
{
    use AuthorizesAdmin;

    #[Locked]
    public string $shopId;

    public string $deviceName = '';

    /**
     * The plaintext token exists only here, between issuance and the moment the
     * administrator dismisses the dialog. It is never stored or logged.
     */
    #[Locked]
    public ?string $issuedToken = null;

    #[Locked]
    public ?string $issuedForName = null;

    #[Locked]
    public ?string $targetDeviceId = null;

    #[Locked]
    public ?string $activityDeviceId = null;

    #[Computed]
    public function shop(): Shop
    {
        return Shop::query()->findOrFail($this->shopId);
    }

    #[On('shop-status-changed')]
    public function refresh(): void
    {
        unset($this->shop);
    }

    public function register(DeviceManagementService $devices): void
    {
        $this->validate(['deviceName' => ['required', 'string', 'max:255']]);

        $issued = $devices->register($this->shop, trim($this->deviceName), auth()->user());

        $this->reset('deviceName');
        $this->issuedToken = $issued->plainTextToken;
        $this->issuedForName = $issued->device->name;

        Flux::modal('register-device')->close();
        Flux::modal('device-token')->show();
        Flux::toast(text: __('ui.devices.registered_done'), variant: 'success');
        $this->dispatch('devices-changed');
    }

    public function confirmRevoke(string $deviceId): void
    {
        $this->targetDeviceId = $this->findDevice($deviceId)->getKey();
        Flux::modal('revoke-device')->show();
    }

    public function revoke(DeviceManagementService $devices): void
    {
        $devices->revoke($this->findDevice((string) $this->targetDeviceId), auth()->user());

        $this->targetDeviceId = null;
        Flux::modal('revoke-device')->close();
        Flux::toast(text: __('ui.devices.revoked_done'), variant: 'warning');
        $this->dispatch('devices-changed');
    }

    public function confirmRotate(string $deviceId): void
    {
        $this->targetDeviceId = $this->findDevice($deviceId)->getKey();
        Flux::modal('rotate-token')->show();
    }

    public function rotate(DeviceManagementService $devices): void
    {
        $issued = $devices->rotateToken($this->findDevice((string) $this->targetDeviceId), auth()->user());

        $this->targetDeviceId = null;
        $this->issuedToken = $issued->plainTextToken;
        $this->issuedForName = $issued->device->name;

        Flux::modal('rotate-token')->close();
        Flux::modal('device-token')->show();
        Flux::toast(text: __('ui.devices.rotated_done'), variant: 'success');
        $this->dispatch('devices-changed');
    }

    public function dismissToken(): void
    {
        $this->issuedToken = null;
        $this->issuedForName = null;
    }

    public function showActivity(string $deviceId): void
    {
        $this->activityDeviceId = $this->findDevice($deviceId)->getKey();
        Flux::modal('device-activity')->show();
    }

    /**
     * Always scoped to this shop, so an id from another shop is a 404.
     */
    private function findDevice(string $deviceId): ShopDevice
    {
        return $this->shop->devices()->findOrFail($deviceId);
    }

    public function render(FleetVersions $versions): View
    {
        $devices = $this->shop->devices()->with('shop:id,status')->orderBy('created_at')->orderBy('id')->get();

        $activity = null;

        if ($this->activityDeviceId !== null) {
            $device = $devices->firstWhere('id', $this->activityDeviceId);

            $activity = $device === null ? null : [
                'device' => $device,
                'events' => SyncEvent::query()->where('device_id', $device->getKey())->orderByDesc('received_at')->orderByDesc('id')->limit(8)->get(),
                'commands' => RemoteCommand::query()->where('device_id', $device->getKey())->orderByDesc('requested_at')->limit(8)->get(),
                'audit' => AuditLog::query()->where('device_id', $device->getKey())->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get(),
            ];
        }

        return view('livewire.shops.devices-panel', [
            'devices' => $devices,
            'activity' => $activity,
            'targetDevice' => $this->targetDeviceId ? $devices->firstWhere('id', $this->targetDeviceId) : null,
            'fleetVersions' => $versions,
            'latestVersion' => $versions->summary()['latest'],
        ]);
    }
}
