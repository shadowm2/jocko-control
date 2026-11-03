<?php

namespace App\Mail;

use App\Models\ShopDevice;
use App\Support\Format;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Device X of shop Y needs attention" / "is fine again".
 */
class DeviceAlertMail extends Mailable
{
    /**
     * @param  'problem'|'recovered'  $kind
     * @param  'offline'|'backlog'|null  $reason
     * @param  array{offline?: bool, pending_events?: ?int, oldest_pending_seconds?: ?int}  $row
     */
    public function __construct(
        public readonly ShopDevice $device,
        public readonly string $kind,
        public readonly ?string $reason = null,
        public readonly array $row = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("ui.alert_mail.subject_{$this->kind}", [
            'shop' => (string) data_get($this->device, 'shop.name', ''),
            'device' => $this->device->name,
        ]));
    }

    public function content(): Content
    {
        return new Content(text: 'emails.device-alert', with: [
            'detail' => $this->detail(),
            'url' => route('shops.show', $this->device->shop_id),
        ]);
    }

    private function detail(): string
    {
        if ($this->kind === 'recovered') {
            return (string) __('ui.alert_mail.recovered');
        }

        if ($this->reason === 'offline') {
            return (string) __('ui.dashboard.silent_for', ['time' => Format::duration(now()->diffInSeconds($this->device->last_seen_at, true))]);
        }

        return (string) __('ui.dashboard.backlog', [
            'count' => Format::number((int) ($this->row['pending_events'] ?? 0)),
            'age' => Format::duration((int) ($this->row['oldest_pending_seconds'] ?? 0)),
        ]);
    }
}
