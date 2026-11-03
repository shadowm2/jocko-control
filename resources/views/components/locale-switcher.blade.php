<div {{ $attributes->class('flex items-center gap-2 text-xs') }} aria-label="{{ __('ui.nav.language') }}">
    @foreach (config('jocko.locales') as $code => $name)
        <form method="POST" action="{{ route('locale.update', $code) }}">
            @csrf
            <button type="submit" @class(['underline-offset-2 hover:underline', 'font-semibold' => app()->getLocale() === $code, 'text-zinc-500' => app()->getLocale() !== $code]) @disabled(app()->getLocale() === $code)>{{ $name }}</button>
        </form>
    @endforeach
</div>
