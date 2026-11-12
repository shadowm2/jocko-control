<?php

namespace Tests\Feature\Admin;

use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Livewire\Shops\Form;
use App\Livewire\Shops\Show;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\Channels\KavenegarChannel;
use App\Notifications\DailyDigestNotification;
use App\Services\DigestSender;
use App\Services\ShopDigest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\BuildsShopMirror;
use Tests\TestCase;

class DailyDigestTest extends TestCase
{
    use BuildsShopMirror, RefreshDatabase;

    private Shop $shop;

    private User $admin;

    private int $next = 1;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $this->travelTo(CarbonImmutable::parse('2026-11-12 18:30:00', 'UTC'));
        $this->admin = User::factory()->create();
        $this->shop = Shop::factory()->create([
            'name' => 'Alpha Garage', 'timezone' => 'UTC', 'email' => 'owner@alpha.test', 'phone' => '09121234567',
            'digest_channels' => ['mail', 'sms', 'internal'], 'digest_time' => '18:00',
        ]);
    }

    private function sold(float $total, string $status = 'completed', string $at = '2026-11-12T10:00:00Z'): void
    {
        $id = $this->next++;
        $this->mirror($this->shop, 'order', $id, ['status' => $status, 'created_at' => $at]);
        $this->mirror($this->shop, 'order_item', $this->next++, ['order_id' => $id, 'total' => $total]);
    }

    private function paid(string $method, float $amount, string $on = '2026-11-12', array $extra = []): void
    {
        $this->mirror($this->shop, 'payment', $this->next++, ['method' => $method, 'amount' => $amount, 'paid_at' => $on] + $extra);
    }

    public function test_the_report_adds_up_the_shops_day(): void
    {
        $this->sold(1000);
        $this->sold(500);
        $this->sold(9000, 'cancelled');
        $this->sold(7000, at: '2026-11-11T10:00:00Z');
        $this->paid('cash', 800);
        $this->paid('pos', 300);
        $this->paid('cash', 5000, '2026-11-11');
        $this->paid('cheque', 2000, extra: ['cheque_status' => 'bounced']);
        $this->mirror($this->shop, 'cash_close', $this->next++, ['business_date' => '2026-11-12', 'expected_cash' => 800, 'counted_cash' => 790, 'difference' => -10]);
        $this->paid('cheque', 100, '2026-11-01', ['cheque_status' => 'pending', 'cheque_due_at' => '2026-11-10']);
        $this->paid('cheque', 250, '2026-11-01', ['cheque_status' => 'pending', 'cheque_due_at' => '2026-11-14']);
        $this->paid('cheque', 999, '2026-11-01', ['cheque_status' => 'pending', 'cheque_due_at' => '2026-12-30']);

        $digest = app(ShopDigest::class)->build($this->shop);

        $this->assertSame('2026-11-12', $digest['date']);
        $this->assertSame([2, 1500.0], [$digest['orders'], $digest['sales']]);
        $this->assertSame(1100.0, $digest['collected']);
        $this->assertSame(['cash' => 800.0, 'pos' => 300.0], $digest['methods']);
        $this->assertSame(['expected' => 800.0, 'counted' => 790.0, 'difference' => -10.0], $digest['cash_close']);
        $this->assertSame([1, 100.0, 1, 250.0], [$digest['cheques_overdue_count'], $digest['cheques_overdue_total'], $digest['cheques_soon_count'], $digest['cheques_soon_total']]);
    }

    public function test_a_quiet_day_is_a_report_of_nothing_and_an_unclosed_till(): void
    {
        $digest = app(ShopDigest::class)->build($this->shop);

        $this->assertSame([0, 0.0, 0.0, []], [$digest['orders'], $digest['sales'], $digest['collected'], $digest['methods']]);
        $this->assertNull($digest['cash_close']);
    }

    public function test_the_shops_settings_decide_the_channels(): void
    {
        Notification::fake();
        $this->shop->update(['digest_channels' => ['internal']]);

        app(DigestSender::class)->send($this->shop);

        Notification::assertSentTo($this->admin, DailyDigestNotification::class, fn (DailyDigestNotification $n, array $channels): bool => $channels === ['database']);
        Notification::assertSentOnDemandTimes(DailyDigestNotification::class, 0);
    }

    public function test_mail_and_sms_go_to_the_shops_own_contacts_unless_overridden(): void
    {
        Notification::fake();

        app(DigestSender::class)->send($this->shop);

        Notification::assertSentOnDemand(DailyDigestNotification::class, fn (DailyDigestNotification $n, array $channels, AnonymousNotifiable $notifiable): bool => $channels === ['mail'] && $notifiable->routes['mail'] === 'owner@alpha.test');
        Notification::assertSentOnDemand(DailyDigestNotification::class, fn (DailyDigestNotification $n, array $channels, AnonymousNotifiable $notifiable): bool => $channels === [KavenegarChannel::class] && $notifiable->routes[KavenegarChannel::class] === '09121234567');

        Notification::fake();
        $this->shop->update(['digest_email' => 'boss@alpha.test', 'digest_mobile' => '09350000000']);

        app(DigestSender::class)->send($this->shop->fresh());

        Notification::assertSentOnDemand(DailyDigestNotification::class, fn (DailyDigestNotification $n, array $channels, AnonymousNotifiable $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'boss@alpha.test');
        Notification::assertSentOnDemand(DailyDigestNotification::class, fn (DailyDigestNotification $n, array $channels, AnonymousNotifiable $notifiable): bool => ($notifiable->routes[KavenegarChannel::class] ?? null) === '09350000000');
    }

    public function test_the_report_reads_as_plain_lines_for_every_channel(): void
    {
        $this->sold(1500);
        $this->paid('cash', 800);
        $notification = new DailyDigestNotification($this->shop->getKey(), 'Alpha Garage', app(ShopDigest::class)->build($this->shop), ['sms']);

        $text = $notification->toSms(new AnonymousNotifiable);

        $this->assertStringContainsString('Alpha Garage', $text);
        $this->assertStringContainsString('Sales today: 1,500 (1 orders)', $text);
        $this->assertStringContainsString('Collected today: 800', $text);
        $this->assertStringContainsString('The till has not been closed today.', $text);
        $this->assertSame($notification->lines(), $notification->toArray(new AnonymousNotifiable)['lines']);
    }

    public function test_a_shop_with_no_channels_gets_nothing(): void
    {
        Notification::fake();
        $this->shop->update(['digest_channels' => null]);

        $this->assertFalse(app(DigestSender::class)->isDue($this->shop));
        $this->assertSame(0, app(DigestSender::class)->sendDue());

        Notification::assertNothingSent();
    }

    public function test_it_is_sent_once_a_day_after_the_send_time(): void
    {
        Notification::fake();
        $sender = app(DigestSender::class);

        $this->travelTo(CarbonImmutable::parse('2026-11-12 17:59:00', 'UTC'));
        $this->assertFalse($sender->isDue($this->shop));
        $this->assertSame(0, $sender->sendDue());

        $this->travelTo(CarbonImmutable::parse('2026-11-12 18:00:00', 'UTC'));
        $this->assertSame(1, $sender->sendDue());
        $this->assertSame('2026-11-12', $this->shop->fresh()->digest_last_sent_on->toDateString());

        $this->travelTo(CarbonImmutable::parse('2026-11-12 23:00:00', 'UTC'));
        $this->assertSame(0, $sender->sendDue());

        $this->travelTo(CarbonImmutable::parse('2026-11-13 18:10:00', 'UTC'));
        $this->assertSame(1, $sender->sendDue());
    }

    public function test_the_send_time_is_in_the_shops_own_timezone(): void
    {
        $this->shop->update(['timezone' => 'Asia/Tokyo', 'digest_time' => '09:00']); // 18:30 UTC is 03:30 there

        $this->assertFalse(app(DigestSender::class)->isDue($this->shop->fresh()));

        $this->travelTo(CarbonImmutable::parse('2026-11-13 00:30:00', 'UTC')); // 09:30 in Tokyo
        $this->assertTrue(app(DigestSender::class)->isDue($this->shop->fresh()));
    }

    public function test_a_suspended_shop_is_not_sent_a_report(): void
    {
        $this->shop->update(['status' => 'suspended']);

        $this->assertFalse(app(DigestSender::class)->isDue($this->shop->fresh()));
    }

    public function test_the_scheduled_command_sends_what_is_due(): void
    {
        Notification::fake();

        $this->artisan('jocko:send-digests')->assertSuccessful();

        Notification::assertSentTo($this->admin, DailyDigestNotification::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shop.digest_sent', 'shop_id' => $this->shop->id]);
    }

    public function test_sms_goes_to_kavenegar_and_the_key_never_shows_in_a_failure(): void
    {
        config(['services.kavenegar' => ['enabled' => true, 'key' => 'SECRET-KEY', 'sender' => '10004346']]);
        $this->shop->update(['digest_channels' => ['sms']]);
        Http::fake(['api.kavenegar.com/*' => Http::response(['return' => ['status' => 200]])]);

        $result = app(DigestSender::class)->send($this->shop);

        $this->assertSame(['sms'], $result['sent']);
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://api.kavenegar.com/v1/SECRET-KEY/sms/send.json')
            && $r['receptor'] === '09121234567' && str_contains($r['message'], 'Alpha Garage') && $r['sender'] === '10004346');

        Http::swap(new Factory);
        Http::fake(['api.kavenegar.com/*' => Http::response('no', 500)]);
        $failed = app(DigestSender::class)->send($this->shop, markSent: false);

        $this->assertSame(['sms'], array_keys($failed['failed']));
        $this->assertStringNotContainsString('SECRET-KEY', json_encode($failed['failed']));
        $this->assertStringNotContainsString('SECRET-KEY', json_encode(AuditLog::query()->pluck('metadata')));
    }

    public function test_sms_is_only_logged_while_sms_is_switched_off(): void
    {
        config(['services.kavenegar' => ['enabled' => false, 'key' => null, 'sender' => null]]);
        $this->shop->update(['digest_channels' => ['sms']]);
        Http::fake();
        Log::spy();

        $result = app(DigestSender::class)->send($this->shop);

        $this->assertSame(['sms'], $result['sent']);
        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => str_contains($message, 'SMS not sent'))->once();
    }

    public function test_one_failing_channel_does_not_stop_the_others_and_is_audited(): void
    {
        config(['services.kavenegar' => ['enabled' => true, 'key' => null, 'sender' => null]]);
        $this->shop->update(['digest_channels' => ['sms', 'internal']]);

        $result = app(DigestSender::class)->send($this->shop);

        $this->assertSame(['internal'], $result['sent']);
        $this->assertSame(['sms'], array_keys($result['failed']));
        $this->assertSame(1, $this->admin->notifications()->count());
        $log = AuditLog::where('action', 'shop.digest_sent')->sole();
        $this->assertSame(['internal'], $log->metadata['channels']);
        $this->assertArrayHasKey('sms', $log->metadata['failed']);
    }

    public function test_the_inbox_shows_reports_and_marks_them_read(): void
    {
        app(DigestSender::class)->send($this->shop->fresh()->forceFill(['digest_channels' => ['internal']]));
        $this->actingAs($this->admin);

        $page = Livewire::test(NotificationsIndex::class)
            ->assertSee('data-notification="unread"', false)
            ->assertSee('Alpha Garage');

        $page->call('markAllRead')->assertSee('data-notification="read"', false);
        $this->assertSame(0, $this->admin->unreadNotifications()->count());
    }

    public function test_the_inbox_is_calm_when_empty(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(NotificationsIndex::class)->assertSee('data-no-notifications', false);
    }

    public function test_send_report_now_is_a_test_that_does_not_use_up_the_day(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);

        Livewire::test(Show::class, ['shop' => $this->shop])->assertSee('data-send-digest', false)->call('sendDigestNow');

        Notification::assertSentTo($this->admin, DailyDigestNotification::class);
        $this->assertNull($this->shop->fresh()->digest_last_sent_on);
        $this->assertTrue(app(DigestSender::class)->isDue($this->shop->fresh()));
    }

    public function test_the_shop_form_saves_the_report_settings(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Form::class, ['shop' => $this->shop])
            ->assertSet('digest_channels', ['mail', 'sms', 'internal'])
            ->set('digest_channels', ['internal', 'mail'])
            ->set('digest_time', '20:15')
            ->set('digest_email', 'boss@alpha.test')
            ->call('save')
            ->assertHasNoErrors();

        $shop = $this->shop->fresh();
        $this->assertSame(['mail', 'internal'], $shop->digestChannels());
        $this->assertSame('20:15', $shop->digest_time);
        $this->assertSame('boss@alpha.test', $shop->digestEmail());
    }

    public function test_turning_every_channel_off_stops_the_report(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Form::class, ['shop' => $this->shop])->set('digest_channels', [])->call('save')->assertHasNoErrors();

        $this->assertSame([], $this->shop->fresh()->digestChannels());
        $this->assertNull($this->shop->fresh()->getRawOriginal('digest_channels'));
    }

    public function test_the_form_wants_somewhere_to_send_it_and_a_valid_time(): void
    {
        $this->actingAs($this->admin);
        $this->shop->update(['email' => null, 'phone' => null]);

        Livewire::test(Form::class, ['shop' => $this->shop->fresh()])
            ->set('digest_channels', ['mail', 'sms'])
            ->set('digest_time', '25:99')
            ->call('save')
            ->assertHasErrors(['digest_email', 'digest_mobile', 'digest_time']);

        Livewire::test(Form::class, ['shop' => $this->shop->fresh()])
            ->set('digest_channels', ['fax'])
            ->call('save')
            ->assertHasErrors(['digest_channels.0']);
    }
}
