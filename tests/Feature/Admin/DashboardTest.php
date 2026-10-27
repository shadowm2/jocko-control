<?php

namespace Tests\Feature\Admin;

use App\Livewire\Dashboard;
use App\Models\AuditLog;
use App\Models\RemoteCommand;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\SyncEvent;
use App\Models\User;
use App\Services\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->startOfSecond());
    }

    public function test_it_reports_zero_for_an_empty_system(): void
    {
        $summary = app(DashboardMetrics::class)->summary();

        $this->assertSame(array_fill_keys(array_keys($summary), 0), $summary);
        $this->get(route('dashboard'))->assertOk()->assertSeeLivewire(Dashboard::class);
    }

    public function test_it_counts_shops_devices_events_and_commands(): void
    {
        $active = Shop::factory()->count(2)->create();
        $suspended = Shop::factory()->suspended()->create();

        $online = ShopDevice::factory()->for($active[0])->seenAt(now()->subMinutes(14))->create();
        ShopDevice::factory()->for($active[0])->seenAt(now()->subMinutes(16))->create();             // offline
        ShopDevice::factory()->for($active[1])->create();                                          // never connected
        ShopDevice::factory()->for($active[1])->revoked()->seenAt(now())->create();                // revoked: not active
        ShopDevice::factory()->for($suspended)->seenAt(now())->create();                           // shop suspended: can't connect
        ShopDevice::factory()->for($active[1])->seenAt(now())->withSyncError()->create();          // online and failing
        ShopDevice::factory()->for($active[1])->seenAt(now())->state(['last_sync_error' => 'old', 'last_sync_error_at' => now()->subDays(3)])->create();

        SyncEvent::factory()->count(3)->create(['device_id' => $online->id]);

        RemoteCommand::factory()->count(2)->create(['device_id' => $online->id]);
        RemoteCommand::factory()->expired()->create(['device_id' => $online->id]);
        RemoteCommand::factory()->completed()->create(['device_id' => $online->id]);

        $summary = app(DashboardMetrics::class)->summary();

        $this->assertSame(3, $summary['shops_total']);
        $this->assertSame(2, $summary['shops_active']);
        $this->assertSame(1, $summary['shops_suspended']);
        $this->assertSame(5, $summary['devices_active']);
        $this->assertSame(3, $summary['devices_online']);
        $this->assertSame(1, $summary['devices_offline']);
        $this->assertSame(1, $summary['devices_never_connected']);
        $this->assertSame(1, $summary['devices_failing']);
        $this->assertSame(3, $summary['events_total']);
        $this->assertSame(2, $summary['commands_pending']);
    }

    public function test_a_never_connected_device_is_not_online(): void
    {
        ShopDevice::factory()->create();

        $summary = app(DashboardMetrics::class)->summary();

        $this->assertSame(0, $summary['devices_online']);
        $this->assertSame(0, $summary['devices_offline']);
        $this->assertSame(1, $summary['devices_never_connected']);
    }

    public function test_the_online_threshold_is_configurable(): void
    {
        ShopDevice::factory()->seenAt(now()->subMinutes(20))->create();

        $this->assertSame(0, app(DashboardMetrics::class)->summary()['devices_online']);

        config(['jocko.online_threshold_minutes' => 30]);

        $this->assertSame(1, app(DashboardMetrics::class)->summary()['devices_online']);
    }

    public function test_the_page_shows_metric_tiles_recent_activity_and_sync_errors(): void
    {
        $shop = Shop::factory()->create(['name' => 'Alpha Garage']);
        ShopDevice::factory()->for($shop)->withSyncError('Local database locked')->create(['name' => 'Front desk']);
        AuditLog::create(['shop_id' => $shop->id, 'action' => 'shop.created', 'user_id' => auth()->id()]);

        Livewire::test(Dashboard::class)
            ->assertSee(__('ui.dashboard.devices_failing'))
            ->assertSee('Local database locked')
            ->assertSee('Alpha Garage')
            ->assertSee(__('ui.audit_action.shop_created'));
    }

    public function test_the_page_does_not_issue_a_query_per_row(): void
    {
        $shops = Shop::factory()->count(5)->create();
        foreach ($shops as $shop) {
            ShopDevice::factory()->for($shop)->withSyncError()->create();
            AuditLog::create(['shop_id' => $shop->id, 'action' => 'shop.created']);
        }

        \DB::enableQueryLog();
        Livewire::test(Dashboard::class);
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(20, $queries);
    }
}
