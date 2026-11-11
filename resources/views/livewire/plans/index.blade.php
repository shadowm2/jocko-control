<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('ui.plans.title') }}</flux:heading>
            <flux:subheading>{{ __('ui.plans.subtitle') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('ui.plans.create') }}</flux:button>
    </div>

    <flux:card class="table-card overflow-x-auto p-0!">
        @if ($plans->isEmpty())
            <div class="p-10 text-center">
                <flux:icon.credit-card class="mx-auto mb-3 size-10 text-zinc-300" />
                <flux:heading>{{ __('ui.plans.empty') }}</flux:heading>
                <flux:text class="mt-1">{{ __('ui.plans.empty_hint') }}</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('ui.plans.name') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.plans.price') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.plans.billing_days') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.plans.max_devices') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.plans.shops') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.common.status') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($plans as $plan)
                        <flux:table.row :key="$plan->id">
                            <flux:table.cell variant="strong">{{ $plan->name }}</flux:table.cell>
                            <flux:table.cell><span dir="ltr">{{ number_format($plan->price) }}</span></flux:table.cell>
                            <flux:table.cell>{{ $plan->billing_days }}</flux:table.cell>
                            <flux:table.cell>{{ $plan->max_devices ?? __('ui.plans.unlimited') }}</flux:table.cell>
                            <flux:table.cell>{{ $plan->shops_count }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$plan->is_active ? 'green' : 'zinc'" inset="top bottom">{{ $plan->is_active ? __('ui.plans.active') : __('ui.plans.inactive') }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center justify-end gap-1">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $plan->id }})" :aria-label="__('ui.common.edit')" />
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $plan->id }})" wire:confirm="{{ __('ui.plans.delete_confirm') }}" :aria-label="__('ui.plans.delete')" />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    <flux:modal name="plan-form" class="md:w-[32rem]">
        <form wire:submit="save" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ $planId ? __('ui.plans.edit') : __('ui.plans.create') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('ui.plans.name') }}</flux:label>
                <flux:input wire:model="name" required />
                <flux:error name="name" />
            </flux:field>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('ui.plans.price') }}</flux:label>
                    <flux:input type="number" min="0" wire:model="price" dir="ltr" required />
                    <flux:error name="price" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('ui.plans.billing_days') }}</flux:label>
                    <flux:input type="number" min="1" wire:model="billing_days" dir="ltr" required />
                    <flux:error name="billing_days" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('ui.plans.max_devices') }}</flux:label>
                <flux:input type="number" min="1" wire:model="max_devices" dir="ltr" />
                <flux:description>{{ __('ui.plans.max_devices_help') }}</flux:description>
                <flux:error name="max_devices" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('ui.shops.notes') }}</flux:label>
                <flux:textarea wire:model="notes" rows="3" />
                <flux:error name="notes" />
            </flux:field>

            <flux:checkbox wire:model="is_active" :label="__('ui.plans.active')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('ui.common.cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('ui.common.save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
