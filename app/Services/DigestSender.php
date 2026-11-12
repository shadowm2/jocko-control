<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ShopStatus;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\Channels\KavenegarChannel;
use App\Notifications\DailyDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * @phpstan-import-type Digest from ShopDigest
 *
 * Sends each shop's daily report through the channels its settings choose.
 */
class DigestSender
{
    public function __construct(
        private readonly ShopDigest $digest,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Whether it is time to send this shop's report: it has channels, it is
     * active, it was not sent today (the shop's day) and its send time has come.
     */
    public function isDue(Shop $shop, ?CarbonImmutable $now = null): bool
    {
        if ($shop->digestChannels() === [] || ! $shop->isActive()) {
            return false;
        }

        $local = ($now ?? CarbonImmutable::now())->setTimezone($shop->timezone);
        $sentOn = $shop->getAttribute('digest_last_sent_on');

        if ($sentOn !== null && $sentOn->toDateString() === $local->toDateString()) {
            return false;
        }

        return $local->format('H:i') >= (string) ($shop->getAttribute('digest_time') ?? '21:00');
    }

    /**
     * Send the reports that are due.
     *
     * @return int how many shops were sent a report
     */
    public function sendDue(?CarbonImmutable $now = null): int
    {
        $sent = 0;

        foreach (Shop::query()->whereNotNull('digest_channels')->where('status', ShopStatus::Active->value)->get() as $shop) {
            if ($this->isDue($shop, $now)) {
                $this->send($shop, null, $now);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Build today's report and send it. One failing channel never stops the others.
     * Sending to a shop counts as that day's report, so it is not repeated.
     *
     * @return array{sent: list<string>, failed: array<string, string>}
     */
    public function send(Shop $shop, ?User $admin = null, ?CarbonImmutable $now = null, bool $markSent = true): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone($shop->timezone);
        $digest = $this->digest->build($shop, $now);
        $channels = $shop->digestChannels();
        $sent = [];
        $failed = [];

        foreach ($channels as $channel) {
            try {
                $this->deliver($shop, $digest, $channel);
                $sent[] = $channel;
            } catch (Throwable $e) {
                $failed[$channel] = $e->getMessage();
            }
        }

        if ($markSent) {
            $shop->forceFill(['digest_last_sent_on' => $now->toDateString()])->saveQuietly();
        }

        $this->audit->record(AuditAction::ShopDigestSent, $admin, $shop, metadata: ['channels' => $sent, 'failed' => $failed, 'date' => $digest['date']]);

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * @param  Digest  $digest
     */
    private function deliver(Shop $shop, array $digest, string $channel): void
    {
        $notification = new DailyDigestNotification($shop->getKey(), $shop->name, $digest, [$channel]);

        match ($channel) {
            'mail' => Notification::route('mail', $shop->digestEmail() ?? throw new \RuntimeException('No email address to send the report to.'))->notifyNow($notification),
            'sms' => Notification::route(KavenegarChannel::class, $shop->digestMobile() ?? throw new \RuntimeException('No mobile number to send the report to.'))->notifyNow($notification),
            'internal' => Notification::sendNow(User::query()->where('is_active', true)->get(), $notification),
            default => null,
        };
    }
}
