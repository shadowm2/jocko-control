<?php

namespace Tests\Feature\Admin;

use App\Mail\DeviceAlertMail;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\User;
use App\Services\DeviceAlerts;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DeviceAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ShopDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(now()->startOfSecond());
        $this->admin = User::factory()->create(['email' => 'admin@example.test']);
        $this->device = ShopDevice::factory()->for(Shop::factory()->create(['name' => 'Alpha']))->seenAt(now()->subHours(2))->create(['name' => 'Front desk']);
    }

    private function check(): array
    {
        return app(DeviceAlerts::class)->check();
    }

    public function test_a_silent_device_is_mentioned_once_to_the_administrators(): void
    {
        $this->assertSame(['alerted' => 1, 'recovered' => 0], $this->check());

        Mail::assertSent(DeviceAlertMail::class, fn (DeviceAlertMail $mail) => $mail->hasTo('admin@example.test')
            && $mail->kind === 'problem' && $mail->reason === 'offline'
            && str_contains($mail->envelope()->subject, 'Front desk') && str_contains($mail->envelope()->subject, 'Alpha'));

        $this->assertSame(['alerted' => 0, 'recovered' => 0], $this->check());
        Mail::assertSentCount(1);
    }

    public function test_the_mail_says_how_long_and_where_to_look(): void
    {
        $this->check();

        Mail::assertSent(DeviceAlertMail::class, function (DeviceAlertMail $mail) {
            $text = $mail->render();

            return str_contains($text, 'Alpha') && str_contains($text, 'Front desk') && str_contains($text, route('shops.show', $this->device->shop));
        });
    }

    public function test_a_device_that_stays_in_trouble_is_mentioned_again_after_a_day(): void
    {
        $this->check();
        $this->travel(23)->hours();
        $this->device->forceFill(['last_seen_at' => now()->subHours(2)])->save();
        $this->assertSame(0, $this->check()['alerted']);

        $this->travel(2)->hours();
        $this->device->forceFill(['last_seen_at' => now()->subHours(2)])->save();
        $this->assertSame(1, $this->check()['alerted']);
        Mail::assertSentCount(2);
    }

    public function test_another_kind_of_trouble_is_news_at_once(): void
    {
        $this->check();

        $this->device->forceFill([
            'last_seen_at' => now(),
            'metadata' => ['alert' => $this->device->fresh()->metadata['alert'], 'local_status' => ['pending_events' => 900, 'oldest_pending_seconds' => 60]],
        ])->save();

        $this->assertSame(1, $this->check()['alerted']);
        Mail::assertSent(DeviceAlertMail::class, fn (DeviceAlertMail $mail) => $mail->reason === 'backlog');
    }

    public function test_a_device_that_is_fine_again_is_announced_once(): void
    {
        $this->check();
        $this->device->forceFill(['last_seen_at' => now()])->save();

        $this->assertSame(['alerted' => 0, 'recovered' => 1], $this->check());
        Mail::assertSent(DeviceAlertMail::class, fn (DeviceAlertMail $mail) => $mail->kind === 'recovered');

        $this->assertSame(['alerted' => 0, 'recovered' => 0], $this->check());
        $this->assertNull($this->device->fresh()->metadata['alert'] ?? null);
    }

    public function test_a_healthy_device_is_never_mentioned(): void
    {
        $this->device->forceFill(['last_seen_at' => now()->subMinute()])->save();

        $this->assertSame(['alerted' => 0, 'recovered' => 0], $this->check());
        Mail::assertNothingSent();
    }

    public function test_the_recipients_can_be_chosen_and_inactive_administrators_are_left_out(): void
    {
        User::factory()->create(['email' => 'away@example.test', 'is_active' => false]);
        $this->check();
        Mail::assertSent(DeviceAlertMail::class, fn (DeviceAlertMail $mail) => $mail->hasTo('admin@example.test') && ! $mail->hasTo('away@example.test'));

        ShopDevice::query()->update(['metadata' => null]);
        config(['jocko.alerts.emails' => 'ops@example.test, boss@example.test']);
        $this->check();
        Mail::assertSent(DeviceAlertMail::class, fn (DeviceAlertMail $mail) => $mail->hasTo('ops@example.test') && $mail->hasTo('boss@example.test'));
    }

    public function test_nothing_is_marked_when_there_is_nobody_to_tell_or_alerts_are_off(): void
    {
        config(['jocko.alerts.notify' => false]);
        $this->assertSame(['alerted' => 0, 'recovered' => 0], $this->check());
        Mail::assertNothingSent();
        $this->assertNull($this->device->fresh()->metadata);

        config(['jocko.alerts.notify' => true]);
        $this->admin->update(['is_active' => false]);
        $this->assertSame(0, $this->check()['alerted']);
        $this->assertNull($this->device->fresh()->metadata);

        $this->admin->update(['is_active' => true]);
        $this->assertSame(1, $this->check()['alerted'], 'the day someone can be told, they are');
    }

    public function test_the_command_runs_every_five_minutes(): void
    {
        $this->artisan('jocko:check-devices')->expectsOutputToContain('Alerted about 1')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'jocko:check-devices'));
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    public function test_the_dashboard_menu_shows_how_many_devices_need_attention(): void
    {
        $this->actingAs($this->admin);
        ShopDevice::factory()->for($this->device->shop)->seenAt(now()->subHours(5))->create();

        $this->get(route('dashboard'))->assertOk()->assertSee('data-flux-badge', false);
    }

    public function test_the_menu_has_no_badge_when_all_is_well(): void
    {
        $this->actingAs($this->admin);
        $this->device->forceFill(['last_seen_at' => now()])->save();

        $this->get(route('dashboard'))->assertOk()->assertDontSee('data-flux-badge', false);
    }
}
