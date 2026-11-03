<?php

namespace App\Services;

use App\Mail\DeviceAlertMail;
use App\Models\ShopDevice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the administrators by email when a device needs attention (silent for
 * too long, or unable to get its changes through, see DashboardMetrics::attention())
 * and again when it is fine, once per problem rather than every few minutes.
 * A device that stays in trouble is mentioned again after `renotify_hours`.
 */
class DeviceAlerts
{
    public function __construct(private readonly DashboardMetrics $metrics) {}

    /**
     * @return array{alerted: int, recovered: int}
     */
    public function check(): array
    {
        $alerted = $recovered = 0;
        $recipients = $this->recipients();

        // Nobody to tell (or told not to): leave every device unmarked, so the day someone is, they hear.
        if ($recipients === [] || ! config('jocko.alerts.notify')) {
            return compact('alerted', 'recovered');
        }

        $inTrouble = collect($this->metrics->attention(limit: PHP_INT_MAX))->keyBy(fn (array $row): string => $row['device']->getKey());

        foreach ($inTrouble as $row) {
            $device = $row['device'];
            $state = $this->alertState($device);
            $reason = $row['offline'] ? 'offline' : 'backlog';

            if ($this->alreadyTold($state, $reason)) {
                continue;
            }

            $this->send($recipients, new DeviceAlertMail($device, 'problem', $reason, $row));
            $this->remember($device, ['notified_at' => now()->toIso8601String(), 'reason' => $reason]);
            $alerted++;
        }

        // Devices we raised the alarm for that are fine now.
        ShopDevice::query()->with('shop:id,name,slug,status')->whereNotNull('metadata->alert')->get()
            ->reject(fn (ShopDevice $device): bool => $inTrouble->has($device->getKey()))
            ->each(function (ShopDevice $device) use ($recipients, &$recovered): void {
                $this->send($recipients, new DeviceAlertMail($device, 'recovered'));
                $this->remember($device, null);
                $recovered++;
            });

        return compact('alerted', 'recovered');
    }

    /**
     * @param  array{notified_at?: string, reason?: string}|null  $state
     */
    private function alreadyTold(?array $state, string $reason): bool
    {
        if ($state === null || ! isset($state['notified_at'])) {
            return false;
        }

        // A different kind of trouble is news; the same one is repeated only now and then.
        return ($state['reason'] ?? null) === $reason
            && now()->lt(CarbonImmutable::parse($state['notified_at'])->addHours((int) config('jocko.alerts.renotify_hours')));
    }

    /**
     * @param  array<string, mixed>|null  $alert
     */
    private function remember(ShopDevice $device, ?array $alert): void
    {
        $metadata = $this->metadataOf($device);

        if ($alert === null) {
            unset($metadata['alert']);
        } else {
            $metadata['alert'] = $alert;
        }

        $device->forceFill(['metadata' => $metadata === [] ? null : $metadata])->saveQuietly();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataOf(ShopDevice $device): array
    {
        $metadata = $device->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @return array{notified_at?: string, reason?: string}|null
     */
    private function alertState(ShopDevice $device): ?array
    {
        $alert = $this->metadataOf($device)['alert'] ?? null;

        return is_array($alert) ? $alert : null;
    }

    /**
     * @param  list<string>  $recipients
     */
    private function send(array $recipients, DeviceAlertMail $mail): void
    {
        Mail::to($recipients)->send($mail);
    }

    /**
     * The addresses in `jocko.alerts.emails`, or every active administrator.
     *
     * @return list<string>
     */
    private function recipients(): array
    {
        $configured = array_values(array_filter(array_map('trim', explode(',', (string) config('jocko.alerts.emails')))));

        return $configured !== [] ? $configured : array_values(array_map('strval', User::query()->where('is_active', true)->pluck('email')->all()));
    }
}
