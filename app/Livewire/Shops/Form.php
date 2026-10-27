<?php

namespace App\Livewire\Shops;

use App\Livewire\Concerns\AuthorizesAdmin;
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
        ];
    }

    public function save(ShopManagementService $shops): void
    {
        $data = $this->validate();

        // Empty optional inputs are stored as NULL rather than empty strings.
        $data = array_map(fn (mixed $value) => $value === '' ? null : $value, $data);
        $data['timezone'] ??= 'Asia/Tehran';

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
        ])->layout('components.layouts.app', ['title' => $title]);
    }
}
