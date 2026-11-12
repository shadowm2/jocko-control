<?php

namespace App\Notifications;

use App\Notifications\Channels\KavenegarChannel;
use App\Services\ShopDigest;
use App\Support\Format;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

/**
 * A shop's end-of-day report. The shop's settings decide which of `mail`, `sms`
 * and `internal` (the administrators' inbox here) it goes through.
 *
 * @phpstan-import-type Digest from \App\Services\ShopDigest
 */
class DailyDigestNotification extends Notification
{
    /**
     * @param  Digest  $digest
     * @param  list<string>  $channels  a subset of Shop::DIGEST_CHANNELS
     */
    public function __construct(
        public readonly string $shopId,
        public readonly string $shopName,
        public readonly array $digest,
        private readonly array $channels,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return array_values(array_filter(array_map(fn (string $channel): ?string => match ($channel) {
            'mail' => 'mail',
            'sms' => KavenegarChannel::class,
            'internal' => 'database',
            default => null,
        }, $this->channels)));
    }

    public function title(): string
    {
        return __('ui.digest.subject', ['shop' => $this->shopName, 'date' => $this->digest['date']]);
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $d = $this->digest;
        $money = fn (float $amount): string => Format::number($amount);
        $lines = [__('ui.digest.line.sales', ['sales' => $money($d['sales']), 'orders' => Format::number($d['orders'])])];

        $methods = [];

        foreach ($d['methods'] as $method => $amount) {
            $methods[] = $this->methodLabel($method).' '.$money($amount);
        }

        $lines[] = __('ui.digest.line.collected', ['amount' => $money($d['collected'])]).($methods === [] ? '' : ' ('.implode('، ', $methods).')');

        $lines[] = $d['cash_close'] === null
            ? __('ui.digest.line.till_open')
            : ($d['cash_close']['difference'] == 0
                ? __('ui.digest.line.till_ok', ['counted' => $money($d['cash_close']['counted'])])
                : __('ui.digest.line.till_off', ['counted' => $money($d['cash_close']['counted']), 'difference' => ($d['cash_close']['difference'] > 0 ? '+' : '').$money($d['cash_close']['difference'])]));

        if ($d['cheques_overdue_count'] > 0) {
            $lines[] = __('ui.digest.line.cheques_overdue', ['count' => Format::number($d['cheques_overdue_count']), 'total' => $money($d['cheques_overdue_total'])]);
        }

        if ($d['cheques_soon_count'] > 0) {
            $lines[] = __('ui.digest.line.cheques_soon', ['count' => Format::number($d['cheques_soon_count']), 'total' => $money($d['cheques_soon_total']), 'days' => ShopDigest::CHEQUE_SOON_DAYS]);
        }

        if ($d['debt_total'] > 0) {
            $lines[] = __('ui.digest.line.debt', ['total' => $money($d['debt_total'])]);
        }

        if ($d['low_stock_count'] > 0) {
            $lines[] = __('ui.digest.line.low_stock', ['count' => Format::number($d['low_stock_count'])]);
        }

        return $lines;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title())->greeting($this->shopName);

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail;
    }

    public function toSms(object $notifiable): string
    {
        return $this->shopName."\n".implode("\n", $this->lines());
    }

    /**
     * @return array{title: string, lines: list<string>, shop_id: string, shop_name: string, date: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'lines' => $this->lines(),
            'shop_id' => $this->shopId,
            'shop_name' => $this->shopName,
            'date' => $this->digest['date'],
        ];
    }

    private function methodLabel(string $method): string
    {
        $key = "ui.shop_data.values.payment.method.{$method}";

        return Lang::has($key) ? (string) __($key) : ($method === '' ? (string) __('ui.common.none') : $method);
    }
}
