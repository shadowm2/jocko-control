<?php

namespace App\Livewire\Shops;

use App\Enums\CommandType;
use App\Enums\DeviceStatus;
use App\Events\ShopChanged;
use App\Livewire\Concerns\AuthorizesAdmin;
use App\Livewire\Concerns\RefreshesLive;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\ShopRecord;
use App\Models\SyncEvent;
use App\Services\DigestSender;
use App\Services\MirrorCheck;
use App\Services\RemoteCommandService;
use App\Services\ShopBillingService;
use App\Services\ShopDataCatalog;
use App\Services\ShopManagementService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesAdmin, RefreshesLive;

    #[Locked]
    public string $shopId;

    /** Blank means the plan's price. */
    public ?string $paymentAmount = null;

    public string $paymentNote = '';

    /**
     * @return list<string>
     */
    protected function liveScopes(): array
    {
        return [ShopChanged::DATA, ShopChanged::COMMANDS];
    }

    public function mount(Shop $shop): void
    {
        $this->shopId = $shop->getKey();
    }

    #[Computed]
    public function shop(): Shop
    {
        return Shop::query()->findOrFail($this->shopId);
    }

    #[On('devices-changed')]
    #[On('commands-changed')]
    public function refresh(): void
    {
        unset($this->shop);
    }

    public function suspend(ShopManagementService $shops): void
    {
        $shops->suspend($this->shop, auth()->user());

        unset($this->shop);
        Flux::modal('suspend-shop')->close();
        Flux::toast(text: __('ui.shops.suspended_done'), variant: 'warning');
        $this->dispatch('shop-status-changed');
    }

    public function reactivate(ShopManagementService $shops): void
    {
        $shops->reactivate($this->shop, auth()->user());

        unset($this->shop);
        Flux::modal('reactivate-shop')->close();
        Flux::toast(text: __('ui.shops.reactivated_done'), variant: 'success');
        $this->dispatch('shop-status-changed');
    }

    public function allowRestore(ShopManagementService $shops): void
    {
        $admin = auth()->user();
        abort_if($admin === null, 403);

        $shops->allowRestore($this->shop(), $admin);

        unset($this->shop);
        Flux::modal('allow-restore')->close();
        Flux::toast(text: __('ui.shops.restore_allowed_done'), variant: 'success');
    }

    public function revokeRestore(ShopManagementService $shops): void
    {
        $admin = auth()->user();
        abort_if($admin === null, 403);

        $shops->revokeRestore($this->shop(), $admin);

        unset($this->shop);
        Flux::modal('allow-restore')->close();
        Flux::toast(text: __('ui.shops.restore_revoked_done'), variant: 'success');
    }

    public function recordPayment(ShopBillingService $billing): void
    {
        $admin = auth()->user();
        abort_if($admin === null, 403);

        $this->validate([
            'paymentAmount' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
            'paymentNote' => ['nullable', 'string', 'max:500'],
        ]);

        $shop = Shop::query()->findOrFail($this->shopId);

        if ($shop->plan_id === null) {
            $this->addError('paymentAmount', __('ui.plans.no_plan_hint'));

            return;
        }

        $billing->recordPayment($shop, $admin, filled($this->paymentAmount) ? (int) $this->paymentAmount : null, $this->paymentNote);

        $this->reset('paymentAmount', 'paymentNote');
        unset($this->shop);
        Flux::modal('record-payment')->close();
        Flux::toast(text: __('ui.plans.payment_recorded_done'), variant: 'success');
    }

    /**
     * Send today's report now through the channels in the shop's settings (a test of them).
     * It does not count as the day's scheduled report.
     */
    public function sendDigestNow(DigestSender $digests): void
    {
        $admin = auth()->user();
        abort_if($admin === null, 403);

        $shop = Shop::query()->findOrFail($this->shopId);

        if ($shop->digestChannels() === []) {
            return;
        }

        $result = $digests->send($shop, $admin, markSent: false);

        if ($result['failed'] === []) {
            Flux::toast(text: __('ui.digest.sent_now', ['channels' => implode(', ', array_map(fn (string $c): string => __("ui.digest.channel.{$c}"), $result['sent']))]), variant: 'success');
        } else {
            Flux::toast(text: __('ui.digest.send_failed', ['channels' => implode(', ', array_keys($result['failed']))]), variant: 'danger');
        }
    }

    /**
     * Ask the shop to send again the record types the copy here is missing records of.
     */
    public function resendMissing(MirrorCheck $mirror, RemoteCommandService $commands): void
    {
        $admin = auth()->user();
        abort_if($admin === null, 403);

        $shop = Shop::query()->findOrFail($this->shopId);
        $check = $mirror->check($shop);

        if ($check['device'] === null || $check['resendable'] === []) {
            return;
        }

        $commands->queue($admin, $check['device'], CommandType::SyncNow, ['entities' => $check['resendable']], now()->addHours(24));

        Flux::toast(text: __('ui.mirror.resend_queued'), variant: 'success');
        $this->dispatch('commands-changed');
    }

    public function render(ShopDataCatalog $catalog, MirrorCheck $mirror): View
    {
        $shop = $this->shop;

        $lastSyncAt = $shop->devices()->max('last_sync_at');

        $events = SyncEvent::query()
            ->where('shop_id', $shop->getKey())
            ->with('device:id,name')
            ->orderByDesc('received_at')->orderByDesc('id')
            ->limit(10)
            ->get();

        // The mirrored record each event is about, when there is one to open.
        $records = ShopRecord::query()
            ->forShop($shop)
            ->whereIn('aggregate_id', $events->pluck('aggregate_id'))
            ->get(['id', 'entity', 'aggregate_id'])
            ->filter(fn (ShopRecord $record): bool => $catalog->has($record->entity))
            ->keyBy('aggregate_id');

        return view('livewire.shops.show', [
            'shop' => $shop->loadMissing('plan'),
            'activeDevices' => $shop->devices()->where('status', DeviceStatus::Active->value)->count(),
            'lastSyncAt' => $lastSyncAt ? Carbon::parse($lastSyncAt, 'UTC') : null,
            'mirror' => $mirror->check($shop),
            'payments' => $shop->subscriptionPayments()->with('recordedBy:id,name')->orderByDesc('period_end')->orderByDesc('id')->limit(10)->get(),
            'events' => $events,
            'eventRecords' => $records,
            'audit' => AuditLog::query()
                ->where('shop_id', $shop->getKey())
                ->with(['user:id,name', 'device:id,name'])
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(10)
                ->get(),
        ])->layout('components.layouts.app', ['title' => $shop->name]);
    }
}
