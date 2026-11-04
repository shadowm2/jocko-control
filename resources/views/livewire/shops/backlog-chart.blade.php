@php
    use App\Support\Format;

    // The chart's own space: the plot sits inside these margins.
    $width = 640;
    $height = 170;
    $left = 40;
    $right = 8;
    $top = 10;
    $bottom = 22;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;

    // A round top for the scale, so the gridlines fall on readable numbers.
    $top_value = max((int) $peak, 1);
    $magnitude = 10 ** (strlen((string) $top_value) - 1);
    $scaleMax = (int) (ceil($top_value / $magnitude) * $magnitude);

    $count = count($points);
    $step = $count > 1 ? $plotWidth / ($count - 1) : $plotWidth;
    $x = fn (int $i): float => round($left + $i * $step, 1);
    $y = fn (int $value): float => round($top + $plotHeight * (1 - $value / $scaleMax), 1);
    $baseline = $top + $plotHeight;

    // Runs of consecutive samples; a silent stretch breaks the line instead of dropping to zero.
    $runs = [];
    $current = [];
    foreach ($points as $i => $point) {
        if ($point['value'] === null) {
            if ($current !== []) {
                $runs[] = $current;
                $current = [];
            }
            continue;
        }
        $current[] = [$i, $point['value']];
    }
    if ($current !== []) {
        $runs[] = $current;
    }

    $tooltips = array_map(fn (array $point): string => $point['value'] === null
        ? Format::dateTime($point['at']).' · '.__('ui.backlog.no_data')
        : Format::dateTime($point['at']).' · '.__('ui.backlog.unsent', ['count' => Format::number($point['value'])]), $points);
@endphp

<flux:card data-backlog-chart wire:poll.60s>
    <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg">{{ __('ui.backlog.title') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ __('ui.backlog.hint') }}</flux:text>
        </div>
        <flux:radio.group wire:model.live="range" variant="segmented" size="sm" data-backlog-range>
            <flux:radio value="24h" :label="__('ui.backlog.range_24h')" />
            <flux:radio value="7d" :label="__('ui.backlog.range_7d')" />
        </flux:radio.group>
    </div>

    @if ($peak === null)
        <flux:text class="py-8 text-center text-zinc-500" data-backlog-empty>{{ __('ui.backlog.empty') }}</flux:text>
    @else
        <div class="mb-2 flex flex-wrap gap-x-6 gap-y-1 text-sm">
            <span>{{ __('ui.backlog.peak') }}: <strong class="tabular-nums" data-backlog-peak="{{ $peak }}">{{ Format::number($peak) }}</strong></span>
            <span>{{ __('ui.backlog.latest') }}: <strong class="tabular-nums" data-backlog-latest="{{ $latest }}">{{ Format::number($latest) }}</strong></span>
        </div>

        <div x-data="{ hover: null, tips: @js($tooltips) }" class="relative">
            <svg dir="ltr" viewBox="0 0 {{ $width }} {{ $height }}" class="block h-auto w-full text-blue-600 dark:text-blue-400" role="img" aria-label="{{ __('ui.backlog.title') }}">
                {{-- Recessive grid: zero, half and the top of the scale. --}}
                @foreach ([0, 0.5, 1] as $fraction)
                    @php $gridY = round($top + $plotHeight * (1 - $fraction), 1); @endphp
                    <line x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ $gridY }}" y2="{{ $gridY }}" class="stroke-zinc-200 dark:stroke-zinc-700" stroke-width="1" />
                    <text x="{{ $left - 6 }}" y="{{ $gridY + 4 }}" text-anchor="end" class="fill-zinc-500 text-[11px]">{{ Format::number((int) round($scaleMax * $fraction)) }}</text>
                @endforeach

                <text x="{{ $left }}" y="{{ $height - 5 }}" text-anchor="start" class="fill-zinc-500 text-[11px]">{{ Format::dateTime($points[0]['at']) }}</text>
                <text x="{{ $width - $right }}" y="{{ $height - 5 }}" text-anchor="end" class="fill-zinc-500 text-[11px]">{{ Format::dateTime($points[$count - 1]['at']) }}</text>

                @foreach ($runs as $run)
                    @if (count($run) === 1)
                        <circle cx="{{ $x($run[0][0]) }}" cy="{{ $y($run[0][1]) }}" r="3.5" fill="currentColor" class="stroke-white dark:stroke-zinc-900" stroke-width="2" />
                    @else
                        @php
                            $line = collect($run)->map(fn (array $p, int $n): string => ($n === 0 ? 'M' : 'L').$x($p[0]).' '.$y($p[1]))->implode(' ');
                            $area = $line.' L'.$x($run[count($run) - 1][0]).' '.$baseline.' L'.$x($run[0][0]).' '.$baseline.' Z';
                        @endphp
                        <path d="{{ $area }}" fill="currentColor" fill-opacity="0.12" />
                        <path d="{{ $line }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                    @endif
                @endforeach

                {{-- The hover layer: a rule at the slice under the pointer, and a target as wide as the slice. --}}
                <line x-show="hover !== null" x-cloak :x1="{{ $left }} + hover * {{ $step }}" :x2="{{ $left }} + hover * {{ $step }}" y1="{{ $top }}" y2="{{ $baseline }}" class="stroke-zinc-400" stroke-width="1" />
                @foreach ($points as $i => $point)
                    <rect x="{{ round($x($i) - $step / 2, 1) }}" y="{{ $top }}" width="{{ round($step, 1) }}" height="{{ $plotHeight }}" fill="transparent" @mouseenter="hover = {{ $i }}" @mouseleave="hover = null" />
                @endforeach
            </svg>

            <div class="mt-1 h-5 text-sm text-zinc-600 dark:text-zinc-300" aria-live="polite" data-backlog-tooltip>
                <span x-show="hover !== null" x-cloak x-text="hover !== null ? tips[hover] : ''"></span>
            </div>
        </div>

        <table class="sr-only" data-backlog-table>
            <caption>{{ __('ui.backlog.title') }}</caption>
            <tbody>
                @foreach ($points as $point)
                    <tr><td>{{ Format::dateTime($point['at']) }}</td><td>{{ $point['value'] === null ? __('ui.backlog.no_data') : Format::number($point['value']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
</flux:card>
