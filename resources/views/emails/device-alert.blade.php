{{ config('app.name') }}

{{ $device->shop?->name }} · {{ $device->name }}
{{ $detail }}

{{ __('ui.alert_mail.open') }}: {{ $url }}
