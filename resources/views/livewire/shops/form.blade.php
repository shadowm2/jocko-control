<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <flux:button variant="subtle" size="sm" icon="arrow-right" class="mb-2 rtl:rotate-0" :href="$shopId ? route('shops.show', $shopId) : route('shops.index')" wire:navigate>{{ __('ui.common.back') }}</flux:button>
        <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
    </div>

    <form wire:submit="save">
        <flux:card class="space-y-6">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('ui.shops.name') }}</flux:label>
                    <flux:input wire:model.blur="name" required />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('ui.shops.slug') }}</flux:label>
                    <flux:input wire:model="slug" dir="ltr" required />
                    <flux:description>{{ __('ui.shops.slug_help') }}</flux:description>
                    <flux:error name="slug" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('ui.shops.owner_name') }}</flux:label>
                    <flux:input wire:model="owner_name" />
                    <flux:error name="owner_name" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('ui.shops.phone') }}</flux:label>
                    <flux:input wire:model="phone" dir="ltr" />
                    <flux:error name="phone" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('ui.shops.email') }}</flux:label>
                    <flux:input type="email" wire:model="email" dir="ltr" />
                    <flux:error name="email" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('ui.shops.timezone') }}</flux:label>
                    <flux:select wire:model="timezone">
                        @foreach ($timezones as $zone)
                            <flux:select.option :value="$zone">{{ $zone }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="timezone" />
                </flux:field>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('ui.plans.plan') }}</flux:label>
                    <flux:select wire:model="plan_id">
                        <flux:select.option value="">{{ __('ui.plans.none') }}</flux:select.option>
                        @foreach ($plans as $plan)
                            <flux:select.option :value="$plan->id">{{ $plan->name }}@unless ($plan->is_active) ({{ __('ui.plans.inactive') }})@endunless</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="plan_id" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('ui.plans.paid_until') }}</flux:label>
                    <flux:input type="date" wire:model="paid_until" dir="ltr" />
                    <flux:error name="paid_until" />
                </flux:field>
            </div>

            <flux:field variant="inline">
                <flux:switch wire:model="keep_original_pictures" />
                <flux:label>{{ __('ui.shops.keep_original_pictures') }}</flux:label>
                <flux:description>{{ __('ui.shops.keep_original_pictures_help') }}</flux:description>
                <flux:error name="keep_original_pictures" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('ui.shops.address') }}</flux:label>
                <flux:textarea wire:model="address" rows="2" />
                <flux:error name="address" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('ui.shops.notes') }}</flux:label>
                <flux:textarea wire:model="notes" rows="4" />
                <flux:error name="notes" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" :href="$shopId ? route('shops.show', $shopId) : route('shops.index')" wire:navigate>{{ __('ui.common.cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ __('ui.common.save') }}</span>
                    <span wire:loading wire:target="save">{{ __('ui.common.loading') }}</span>
                </flux:button>
            </div>
        </flux:card>
    </form>
</div>
