<?php

namespace App\Livewire\Shops;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\Plan;
use App\Models\Shop;
use App\Services\ShopManagementService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    use AuthorizesAdmin;

    #[Locked]
    public ?string $shopId = null;

    public string $name = '';

    public string $slug = '';

    public string $owner_name = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $timezone = 'Asia/Tehran';

    public string $notes = '';

    public string $plan_id = '';

    public string $paid_until = '';

    public bool $keep_original_pictures = true;

    /** @var list<string> */
    public array $digest_channels = [];

    public string $digest_email = '';

    public string $digest_mobile = '';

    public string $digest_time = '21:00';

    public function mount(?Shop $shop = null): void
    {
        if ($shop?->exists) {
            $this->shopId = $shop->getKey();
            $this->fill($shop->only(['name', 'slug', 'timezone']));
            $this->owner_name = (string) $shop->owner_name;
            $this->email = (string) $shop->email;
            $this->phone = (string) $shop->phone;
            $this->address = (string) $shop->address;
            $this->notes = (string) $shop->notes;
            $this->plan_id = (string) $shop->plan_id;
            $this->paid_until = $shop->paidUntil()?->format('Y-m-d') ?? '';
            $this->keep_original_pictures = $shop->keepsOriginalPictures();
            $this->digest_channels = $shop->digestChannels();
            $this->digest_email = (string) $shop->getAttribute('digest_email');
            $this->digest_mobile = (string) $shop->getAttribute('digest_mobile');
            $this->digest_time = (string) ($shop->getAttribute('digest_time') ?? '21:00');
        }
    }

    public function updatedName(string $value): void
    {
        if ($this->shopId === null && $this->slug === '') {
            $this->slug = Str::slug($value);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('shops', 'slug')->ignore($this->shopId)],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'notes' => ['nullable', 'string', 'max:5000'],
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')],
            'paid_until' => ['nullable', 'date'],
            'keep_original_pictures' => ['boolean'],
            'digest_channels' => ['array'],
            'digest_channels.*' => [Rule::in(Shop::DIGEST_CHANNELS)],
            'digest_email' => [Rule::requiredIf(fn (): bool => in_array('mail', $this->digest_channels, true) && $this->email === ''), 'nullable', 'email', 'max:255'],
            'digest_mobile' => [Rule::requiredIf(fn (): bool => in_array('sms', $this->digest_channels, true) && $this->phone === ''), 'nullable', 'regex:/^\+?[0-9]{8,15}$/'],
            'digest_time' => ['required', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
        ];
    }

    public function save(ShopManagementService $shops): void
    {
        $data = $this->validate();

        // Empty optional inputs are stored as NULL rather than empty strings.
        $data = array_map(fn (mixed $value) => $value === '' ? null : $value, $data);
        $data['timezone'] ??= 'Asia/Tehran';
        $data['digest_channels'] = $this->digest_channels === [] ? null : array_values(array_unique($this->digest_channels));

        if ($this->shopId === null) {
            $shop = $shops->create($data, auth()->user());
            session()->flash('status', __('ui.shops.created'));
        } else {
            $shop = $shops->update(Shop::findOrFail($this->shopId), $data, auth()->user());
            session()->flash('status', __('ui.shops.updated'));
        }

        $this->redirectRoute('shops.show', $shop, navigate: true);
    }

    public function render(): View
    {
        $title = $this->shopId === null ? __('ui.shops.create_title') : __('ui.shops.edit_title');

        return view('livewire.shops.form', [
            'title' => $title,
            'timezones' => timezone_identifiers_list(),
            'plans' => Plan::query()->orderByDesc('is_active')->orderBy('price')->get(['id', 'name', 'is_active']),
        ])->layout('components.layouts.app', ['title' => $title]);
    }
}
