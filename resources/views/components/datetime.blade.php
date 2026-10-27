@props(['value' => null, 'ago' => false])

@if ($value)
    <time datetime="{{ $value->toIso8601String() }}" title="{{ $ago ? \App\Support\Format::dateTime($value) : \App\Support\Format::ago($value) }}" {{ $attributes }}>
        {{ $ago ? \App\Support\Format::ago($value) : \App\Support\Format::dateTime($value) }}
    </time>
@else
    <span {{ $attributes->class('text-zinc-400') }}>{{ __('ui.common.never') }}</span>
@endif
