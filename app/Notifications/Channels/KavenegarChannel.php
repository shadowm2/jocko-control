<?php

namespace App\Notifications\Channels;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends a notification's `toSms()` text through Kavenegar's HTTP API. The
 * recipient is the notifiable's `routeNotificationFor(KavenegarChannel::class)`.
 *
 * With JOCKO_SMS_ENABLED=false (outside production) nothing leaves the server: the
 * text is written to the log.
 */
class KavenegarChannel
{
    public function send(AnonymousNotifiable|User $notifiable, Notification $notification): void
    {
        $to = $notifiable->routeNotificationFor(self::class, $notification);

        if (blank($to) || ! method_exists($notification, 'toSms')) {
            return;
        }

        $message = (string) $notification->toSms($notifiable);
        $to = preg_replace('/[^0-9+]/', '', strtr((string) $to, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'])) ?? '';

        if (! config('services.kavenegar.enabled') && ! app()->isProduction()) {
            Log::info('SMS not sent, JOCKO_SMS_ENABLED is false', ['to' => $to, 'message' => $message]);

            return;
        }

        $key = config('services.kavenegar.key');

        if (blank($key)) {
            throw new RuntimeException('The Kavenegar API key is not set (KAVENEGAR_API_KEY).');
        }

        try {
            Http::asForm()->timeout(15)->post("https://api.kavenegar.com/v1/{$key}/sms/send.json", array_filter([
                'receptor' => $to,
                'message' => $message,
                'sender' => config('services.kavenegar.sender'),
            ], fn (mixed $value): bool => filled($value)))->throw();
        } catch (RequestException $e) {
            // The URL holds the API key: never let it reach a log or a page.
            throw new RuntimeException('Kavenegar refused the SMS (HTTP '.$e->response->status().').');
        } catch (ConnectionException) {
            throw new RuntimeException('Kavenegar could not be reached.');
        }
    }
}
