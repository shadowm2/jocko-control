@php
    use App\Support\EntityStyle;
    use App\Support\Format;

    $tableCount = $counts->filter(fn ($n) => $n > 0)->count();
@endphp

<div class="space-y-8" wire:poll.60s.visible>
    <div>
        <flux:button variant="subtle" size="sm" icon="arrow-right" class="mb-2" :href="route('shops.show', $shop)" wire:navigate>{{ $shop->name }}</flux:button>
        <flux:heading size="xl" level="1">{{ __('ui.shop_data.title') }}</flux:heading>
        <flux:subheading>{{ __('ui.shop_data.subtitle', ['shop' => $shop->name]) }}</flux:subheading>
    </div>

    <x-shop-tabs :shop="$shop" current="data" />

    <flux:callout icon="eye" :text="__('ui.shop_data.read_only_notice')" />

    @if ($total === 0)
        <flux:card class="px-6 py-14 text-center">
            <flux:icon.circle-stack class="mx-auto mb-3 size-10 text-zinc-300" />
            <flux:heading>{{ __('ui.shop_data.empty') }}</flux:heading>
            <flux:text class="mt-1">{{ __('ui.shop_data.empty_hint') }}</flux:text>
        </flux:card>
    @else
        {{-- The big number --}}
        <section
            class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-gradient-to-br from-sky-50 via-white to-violet-50 p-6 dark:border-zinc-700 dark:from-sky-950/40 dark:via-zinc-900 dark:to-violet-950/40"
            data-hero
        >
            <div class="pointer-events-none absolute -end-10 -top-10 size-48 rounded-full bg-violet-400/20 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-12 start-1/3 size-40 rounded-full bg-sky-400/20 blur-3xl"></div>

            <div class="relative flex flex-wrap items-end justify-between gap-6">
                <div>
                    <div class="flex items-center gap-2 text-sm text-zinc-500">
                        <flux:icon.circle-stack class="size-4" />
                        {{ __('ui.shop_data.total_records') }}
                    </div>
                    <div
                        class="mt-1 text-5xl font-bold tabular-nums tracking-tight text-zinc-900 dark:text-white"
                        data-total="{{ $total }}"
                        x-data="{ shown: 0, target: {{ (int) $total }},
                            init() {
                                const start = performance.now(), ms = 900;
                                const tick = (now) => {
                                    const t = Math.min(1, (now - start) / ms);
                                    this.shown = Math.round(this.target * (1 - Math.pow(1 - t, 3)));
                                    if (t < 1) requestAnimationFrame(tick);
                                };
                                requestAnimationFrame(tick);
                            },
                            format(n) { return new Intl.NumberFormat(document.documentElement.lang || undefined).format(n); } }"
                        x-text="format(shown)"
                    >{{ Format::number($total) }}</div>
                    <div class="mt-1 text-sm text-zinc-500">{{ trans_choice('ui.shop_data.in_tables', $tableCount, ['count' => Format::number($tableCount)]) }}</div>
                </div>

                <div class="space-y-1 text-sm">
                    <div class="flex items-center gap-2 text-zinc-500">
                        <span class="relative flex size-2.5">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex size-2.5 rounded-full bg-emerald-500"></span>
                        </span>
                        {{ __('ui.shop_data.last_update') }}
                    </div>
                    <div class="text-lg font-medium"><x-datetime :value="$lastUpdate" :ago="true" /></div>
                </div>
            </div>
        </section>

        {{-- Find a table, or hide the empty ones --}}
        <div
            x-data="{ q: '', hideEmpty: false,
                shows(name, count) { return (this.q === '' || name.toLowerCase().includes(this.q.toLowerCase())) && (! this.hideEmpty || count > 0); } }"
            class="space-y-8"
        >
            <div class="flex flex-wrap items-center gap-3">
                <div class="min-w-48 flex-1">
                    <flux:input x-model="q" icon="magnifying-glass" :placeholder="__('ui.shop_data.find_table')" clearable data-find-table />
                </div>
                <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                    <flux:switch x-model="hideEmpty" data-hide-empty />
                    {{ __('ui.shop_data.hide_empty') }}
                </label>
            </div>

            @foreach ($groups as $group => $entities)
                @php
                    $palette = EntityStyle::palette($group);
                    $groupTotal = collect($entities)->sum(fn ($entity) => $counts[$entity] ?? 0);
                @endphp
                <section
                    class="space-y-3"
                    data-group="{{ $group }}"
                    x-show="@js($entities).some(e => shows(@js(collect($entities)->mapWithKeys(fn ($e) => [$e => __('ui.shop_data.entities.'.$e)])->all())[e], @js($counts->all())[e] || 0))"
                    x-transition.opacity
                >
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="flex size-9 items-center justify-center rounded-xl {{ $palette['chip'] }}">
                                <flux:icon :name="EntityStyle::groupIcon($group)" class="size-5" />
                            </span>
                            <flux:heading size="lg">{{ __('ui.shop_data.groups.'.$group) }}</flux:heading>
                        </div>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums {{ $palette['chip'] }}">{{ Format::number($groupTotal) }}</span>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($entities as $entity)
                            @php
                                $count = $counts[$entity] ?? 0;
                                $detail = $details[$entity] ?? ['removed' => 0, 'last_at' => null];
                                $share = $count > 0 ? max(4, (int) round($count / $busiest * 100)) : 0;
                                $name = __('ui.shop_data.entities.'.$entity);
                            @endphp
                            <a
                                href="{{ route('shops.data.entity', [$shop, $entity]) }}"
                                wire:navigate
                                data-entity="{{ $entity }}"
                                data-count="{{ $count }}"
                                x-show="shows(@js($name), {{ $count }})"
                                x-transition.opacity
                                @class([
                                    'group block rounded-xl border bg-white p-4 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-lg dark:bg-zinc-900',
                                    'border-zinc-200 dark:border-zinc-700 '.$palette['ring'].' '.$palette['glow'] => $count > 0,
                                    'border-dashed border-zinc-200 opacity-60 hover:opacity-100 dark:border-zinc-700' => $count === 0,
                                ])
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl transition duration-200 group-hover:scale-110 group-hover:-rotate-6 {{ $count > 0 ? $palette['chip'] : 'bg-zinc-100 text-zinc-400 dark:bg-zinc-800' }}">
                                        <flux:icon :name="EntityStyle::icon($entity)" class="size-5" />
                                    </span>
                                    <span class="text-2xl font-semibold tabular-nums">{{ Format::number($count) }}</span>
                                </div>

                                <div class="mt-3 font-medium">{{ $name }}</div>

                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" role="presentation">
                                    <div class="h-full rounded-full {{ $palette['bar'] }} transition-all duration-700" style="width: {{ $share }}%"></div>
                                </div>

                                <div class="mt-2 flex items-center justify-between text-xs text-zinc-500">
                                    <span>
                                        @if ($detail['last_at'])
                                            {{ __('ui.shop_data.changed') }} <x-datetime :value="$detail['last_at']" :ago="true" />
                                        @else
                                            {{ __('ui.shop_data.nothing_yet') }}
                                        @endif
                                    </span>
                                    @if ($detail['removed'] > 0)
                                        <span class="text-red-500" title="{{ __('ui.shop_data.removed') }}">{{ Format::number($detail['removed']) }} 🗑</span>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        {{-- What changed last --}}
        <section class="space-y-3" data-latest>
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    <flux:icon.bolt class="size-5" />
                </span>
                <flux:heading size="lg">{{ __('ui.shop_data.latest_changes') }}</flux:heading>
            </div>

            <flux:card class="divide-y divide-zinc-100 p-0 dark:divide-zinc-800">
                @foreach ($latest as $record)
                    @php $group = collect($groups)->search(fn ($entities) => in_array($record->entity, $entities, true)); @endphp
                    <a
                        href="{{ route('shops.data.show', [$shop, $record->entity, $record]) }}"
                        wire:navigate
                        class="flex items-center justify-between gap-3 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60"
                        data-latest-record="{{ $record->id }}"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ EntityStyle::palette((string) $group)['chip'] }}">
                                <flux:icon :name="EntityStyle::icon($record->entity)" class="size-4" />
                            </span>
                            <div class="min-w-0">
                                <div class="truncate font-medium">{{ $record->label }}</div>
                                <div class="text-xs text-zinc-500">
                                    {{ __('ui.shop_data.entities.'.$record->entity) }}
                                    @if ($record->removed_at) · <span class="text-red-500">{{ __('ui.shop_data.removed') }}</span> @endif
                                </div>
                            </div>
                        </div>
                        <span class="shrink-0 text-xs text-zinc-500"><x-datetime :value="$record->source_occurred_at" :ago="true" /></span>
                    </a>
                @endforeach
            </flux:card>
        </section>
    @endif
</div>
