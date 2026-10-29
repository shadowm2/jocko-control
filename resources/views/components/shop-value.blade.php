@props(['entity', 'field', 'data', 'shop', 'references' => []])

@php
    use App\Services\ShopDataCatalog;
    use App\Support\Format;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Lang;

    $catalog = app(ShopDataCatalog::class);
    $type = $catalog->fields($entity)[$field] ?? 'string';
    $value = $data[$field] ?? null;
    $target = $catalog->referencedEntity($entity, $field);
    $enumKey = "ui.shop_data.values.{$entity}.{$field}.{$value}";
@endphp

@if ($value === null || $value === '')
    <span class="text-zinc-400">{{ __('ui.common.none') }}</span>
@elseif ($target !== null)
    @php $reference = $references[$target][$value] ?? null; @endphp
    @if ($reference)
        <a class="hover:underline" href="{{ route('shops.data.show', [$shop, $target, $reference]) }}" wire:navigate>
            {{ $reference->label }}
        </a>
        @if ($reference->removed_at)
            <flux:badge size="sm" color="red" class="ms-1">{{ __('ui.shop_data.removed') }}</flux:badge>
        @endif
    @else
        <span class="text-zinc-500">#{{ Format::number((int) $value) }}</span>
    @endif
@elseif ($type === 'bool')
    <flux:badge size="sm" :color="$value ? 'green' : 'zinc'">{{ $value ? __('ui.common.yes') : __('ui.common.no') }}</flux:badge>
@elseif ($type === 'datetime')
    <x-datetime :value="Carbon::parse($value)" />
@elseif ($type === 'date')
    <span>{{ Format::date(Carbon::parse($value)) }}</span>
@elseif ($type === 'decimal' || $type === 'int')
    <span dir="ltr" class="tabular-nums">{{ Format::number($type === 'int' ? (int) $value : (float) $value) }}</span>
@elseif ($type === 'enum')
    <span>{{ Lang::has($enumKey) ? __($enumKey) : $value }}</span>
@elseif ($type === 'text')
    <span class="whitespace-pre-line">{{ $value }}</span>
@else
    <span @if (preg_match('/^[\x20-\x7E]+$/', (string) $value)) dir="ltr" @endif>{{ $value }}</span>
@endif
