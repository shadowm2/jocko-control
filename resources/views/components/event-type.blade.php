@props(['type'])
@php
    $label = \App\Support\Format::eventType($type);
@endphp
{{-- The event's name in words, and the exact type small beside it, for anyone who needs to search or quote it. --}}
<span {{ $attributes }}>
    @if ($label !== null)
        {{ $label }}
        <span
            class="text-xs font-normal text-zinc-500"
            dir="ltr"
        >({{ $type }})</span>
    @else
        <span dir="ltr">{{ $type }}</span>
    @endif
</span>
