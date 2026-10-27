<x-layouts.guest :title="__('ui.auth.title')">
    <div class="mb-8 text-center">
        <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-xl bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
            <flux:icon.server-stack class="size-6" />
        </div>
        <flux:heading size="xl" level="1">{{ __('ui.app_name') }}</flux:heading>
        <flux:subheading>{{ __('ui.auth.subtitle') }}</flux:subheading>
    </div>

    <flux:card>
        <form method="POST" action="{{ route('login') }}" class="space-y-6">
            @csrf

            <flux:field>
                <flux:label>{{ __('ui.auth.email') }}</flux:label>
                <flux:input type="email" name="email" :value="old('email')" required autofocus autocomplete="username" dir="ltr" />
                <flux:error name="email" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('ui.auth.password') }}</flux:label>
                <flux:input type="password" name="password" required autocomplete="current-password" viewable dir="ltr" />
                <flux:error name="password" />
            </flux:field>

            <flux:checkbox name="remember" :label="__('ui.auth.remember')" />

            <flux:button type="submit" variant="primary" class="w-full">{{ __('ui.auth.submit') }}</flux:button>
        </form>
    </flux:card>
</x-layouts.guest>
