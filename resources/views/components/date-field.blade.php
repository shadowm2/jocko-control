@props(['label' => null])

{{--
    A date the server receives as YYYY-MM-DD. Persian gets a Jalali calendar
    (resources/js/datepicker.js); other languages use the browser's own picker.
--}}
@if (app()->getLocale() === 'fa')
    <div
        x-data="jalaliDate"
        x-modelable="value"
        {{ $attributes->whereStartsWith('wire:model') }}
        @keydown.escape="open = false"
        @click.outside="open = false"
        class="relative"
        data-jalali-date
    >
        <flux:field>
            @if ($label)
                <flux:label>{{ $label }}</flux:label>
            @endif
            <flux:input readonly icon="calendar-days" x-bind:value="display" x-on:click="open = !open" dir="ltr" class="cursor-pointer" />
        </flux:field>

        <div x-show="open" x-cloak x-transition.opacity class="absolute left-0 z-20 mt-1 w-64 rounded-lg border border-zinc-200 bg-white p-3 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
            <div class="mb-2 flex items-center justify-between">
                <flux:button type="button" size="xs" variant="ghost" icon="chevron-right" x-on:click="step(-1)" />
                <span class="text-sm font-medium" x-text="title"></span>
                <flux:button type="button" size="xs" variant="ghost" icon="chevron-left" x-on:click="step(1)" />
            </div>

            <div class="grid grid-cols-7 gap-1 text-center text-xs">
                <template x-for="name in weekdays" :key="name">
                    <div class="py-1 text-zinc-500" x-text="name"></div>
                </template>
                <template x-for="(cell, index) in cells" :key="index">
                    <div>
                        <button
                            type="button"
                            x-show="cell"
                            x-text="cell && cell.label"
                            x-on:click="pick(cell.iso)"
                            class="size-8 rounded-md text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700"
                            x-bind:class="{
                                'bg-zinc-900 text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200': cell && cell.iso === value,
                                'font-bold underline': cell && cell.iso === today && cell.iso !== value,
                            }"
                        ></button>
                    </div>
                </template>
            </div>

            <div class="mt-2 flex justify-between border-t border-zinc-100 pt-2 dark:border-zinc-700">
                <flux:button type="button" size="xs" variant="ghost" x-on:click="pick(today)">{{ __('ui.datepicker.today') }}</flux:button>
                <flux:button type="button" size="xs" variant="ghost" x-on:click="clear()">{{ __('ui.datepicker.clear') }}</flux:button>
            </div>
        </div>
    </div>
@else
    <flux:input type="date" :label="$label" dir="ltr" {{ $attributes->whereStartsWith('wire:model') }} />
@endif
