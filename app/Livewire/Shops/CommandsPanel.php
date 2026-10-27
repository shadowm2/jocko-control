<?php

namespace App\Livewire\Shops;

use App\Enums\CommandType;
use App\Enums\DeviceStatus;
use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\RemoteCommand;
use App\Models\Shop;
use App\Services\RemoteCommandService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class CommandsPanel extends Component
{
    use AuthorizesAdmin;

    /**
     * Selectable lifetimes, in hours.
     *
     * @var array<int, int>
     */
    public const EXPIRY_HOURS = [1, 6, 24, 72, 168];

    #[Locked]
    public string $shopId;

    #[Locked]
    public ?string $resultCommandId = null;

    public string $deviceId = '';

    public string $commandType = '';

    public int $expiresInHours = 24;

    public bool $full = false;

    public bool $includeLogs = false;

    public ?int $sinceMinutes = null;

    public bool $confirmed = false;

    #[Computed]
    public function shop(): Shop
    {
        return Shop::query()->findOrFail($this->shopId);
    }

    #[On('shop-status-changed')]
    #[On('devices-changed')]
    public function refresh(): void
    {
        unset($this->shop);
    }

    public function updatedCommandType(): void
    {
        $this->reset('full', 'includeLogs', 'sinceMinutes', 'confirmed');
    }

    public function queue(RemoteCommandService $commands): void
    {
        $this->validate([
            'deviceId' => ['required', Rule::exists('shop_devices', 'id')->where('shop_id', $this->shopId)->where('status', DeviceStatus::Active->value)],
            'commandType' => ['required', Rule::enum(CommandType::class)],
            'expiresInHours' => ['required', 'integer', Rule::in(self::EXPIRY_HOURS)],
            'sinceMinutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $type = CommandType::from($this->commandType);

        $this->validate(['confirmed' => $type->isSensitive() ? ['accepted'] : ['boolean']]);

        $commands->queue(
            auth()->user(),
            $this->shop->devices()->findOrFail($this->deviceId),
            $type,
            $this->parameters($type),
            now()->addHours($this->expiresInHours),
        );

        $this->reset('commandType', 'full', 'includeLogs', 'sinceMinutes', 'confirmed');
        Flux::toast(text: __('ui.commands.queued_done'), variant: 'success');
        $this->dispatch('commands-changed');
    }

    public function showResult(string $commandId): void
    {
        $this->resultCommandId = RemoteCommand::query()->where('shop_id', $this->shopId)->findOrFail($commandId)->getKey();
        Flux::modal('command-result')->show();
    }

    /**
     * Only the parameters that make sense for the chosen command are sent.
     *
     * @return array<string, mixed>
     */
    private function parameters(CommandType $type): array
    {
        return match ($type) {
            CommandType::SyncNow => $this->full ? ['full' => true] : [],
            CommandType::CollectDiagnostics => array_filter([
                'include_logs' => $this->includeLogs ?: null,
                'since_minutes' => $this->sinceMinutes,
            ], fn (mixed $value) => $value !== null),
            CommandType::CheckVersion, CommandType::RefreshConfig => [],
        };
    }

    public function render(): View
    {
        $shop = $this->shop;

        return view('livewire.shops.commands-panel', [
            'shop' => $shop,
            'activeDevices' => $shop->devices()->where('status', DeviceStatus::Active->value)->orderBy('name')->get(['id', 'name']),
            'commands' => RemoteCommand::query()
                ->where('shop_id', $shop->getKey())
                ->with(['device:id,name', 'requester:id,name'])
                ->orderByDesc('requested_at')->orderByDesc('id')
                ->limit(10)
                ->get(),
            'selectedType' => CommandType::tryFrom($this->commandType),
            'resultCommand' => $this->resultCommandId
                ? RemoteCommand::query()->where('shop_id', $shop->getKey())->find($this->resultCommandId)
                : null,
        ]);
    }
}
