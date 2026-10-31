@props(['labels', 'series', 'labelEvery' => 1])

@php
    use App\Support\Format;

    $colors = ['bg-blue-500', 'bg-amber-500', 'bg-green-500'];
    $max = max(1, ...array_map(fn (array $values): float => max([0, ...$values]), array_values($series)));
@endphp

<div {{ $attributes }}>
    @if (count($series) > 1)
        <div class="mb-2 flex flex-wrap gap-4 text-xs text-zinc-500">
            @foreach (array_keys($series) as $index => $name)
                <span class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm {{ $colors[$index % count($colors)] }}"></span>{{ $name }}</span>
            @endforeach
        </div>
    @endif

    <div class="flex h-40 items-end gap-1" role="img" aria-label="{{ implode(' / ', array_keys($series)) }}">
        @foreach ($labels as $position => $label)
            <div class="flex h-full min-w-0 flex-1 items-end justify-center gap-0.5">
                @foreach (array_values($series) as $index => $values)
                    @php $value = $values[$position] ?? 0; @endphp
                    <div
                        class="w-full max-w-5 rounded-t {{ $colors[$index % count($colors)] }} {{ $value > 0 ? 'min-h-px' : '' }}"
                        style="height: {{ $value / $max * 100 }}%"
                        title="{{ $label }} — {{ array_keys($series)[$index] }}: {{ Format::number($value) }}"
                    ></div>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="mt-1 flex gap-1 text-[11px] text-zinc-500">
        @foreach ($labels as $position => $label)
            <div class="min-w-0 flex-1 truncate text-center">{{ $position % $labelEvery === 0 ? $label : '' }}</div>
        @endforeach
    </div>
</div>
