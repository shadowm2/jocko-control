<?php

namespace Tests\Feature\Admin;

use App\Livewire\Shops\BacklogChart;
use App\Models\DeviceBacklogSample;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\User;
use App\Services\BacklogHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\CreatesDevices;
use Tests\TestCase;

class BacklogHistoryTest extends TestCase
{
    use CreatesDevices, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        // Right on a slice boundary of both ranges, so the sample times below are exact.
        $this->travelTo(now()->startOfDay()->addHours(12)->addMinutes(10));
        $this->actingAs(User::factory()->create());
        $this->shop = Shop::factory()->create();
    }

    private function sample(ShopDevice $device, int $pending, int $minutesAgo): void
    {
        DeviceBacklogSample::query()->create(['device_id' => $device->id, 'pending_events' => $pending, 'sampled_at' => now()->subMinutes($minutesAgo)]);
    }

    /**
     * @return list<?int>
     */
    private function values(string $range = '24h'): array
    {
        return array_column(app(BacklogHistory::class)->series($this->shop, $range), 'value');
    }

    public function test_a_heartbeat_leaves_a_sample_at_most_every_few_minutes(): void
    {
        $issued = $this->issueDevice($this->shop);
        $beat = fn (int $pending = 4) => $this->postJson('/api/v1/device/heartbeat', ['local_status' => ['state' => 'idle', 'pending_events' => $pending, 'oldest_pending_seconds' => 90]], $this->bearer($issued))->assertOk();

        $beat();
        $beat(9);
        $this->assertSame(1, DeviceBacklogSample::query()->count());
        $this->assertSame(4, DeviceBacklogSample::query()->sole()->pending_events);

        $this->travel(6)->minutes();
        $beat(9);

        $this->assertSame([4, 9], DeviceBacklogSample::query()->orderBy('id')->pluck('pending_events')->all());
    }

    public function test_a_heartbeat_without_a_backlog_figure_leaves_no_sample(): void
    {
        $issued = $this->issueDevice($this->shop);

        $this->postJson('/api/v1/device/heartbeat', ['app_version' => '1.0'], $this->bearer($issued))->assertOk();
        $this->postJson('/api/v1/device/heartbeat', ['local_status' => ['state' => 'idle']], $this->bearer($issued))->assertOk();

        $this->assertSame(0, DeviceBacklogSample::query()->count());
    }

    public function test_it_takes_the_worst_backlog_of_each_slice_and_leaves_gaps_where_the_shop_was_silent(): void
    {
        $a = ShopDevice::factory()->for($this->shop)->create();
        $b = ShopDevice::factory()->for($this->shop)->create();
        $this->sample($a, 5, 5);     // 12:05, the slice 12:00-12:30
        $this->sample($a, 40, 20);   // 11:50, 11:30-12:00
        $this->sample($b, 70, 15);   // 11:55, the same slice: the worse device counts
        $this->sample($a, 8, 190);   // 09:00

        $values = $this->values();

        $this->assertCount(48, $values);
        $this->assertSame(5, $values[47], 'the current slice');
        $this->assertSame(70, $values[46]);
        $this->assertNull($values[45], 'silent');
        $this->assertSame(8, $values[41]);
        $this->assertSame(3, count(array_filter($values, fn (?int $value): bool => $value !== null)));
    }

    public function test_it_ignores_other_shops_and_samples_outside_the_range(): void
    {
        $mine = ShopDevice::factory()->for($this->shop)->create();
        $theirs = ShopDevice::factory()->for(Shop::factory())->create();
        $this->sample($theirs, 999, 5);
        $this->sample($mine, 11, 60 * 25);  // a day and an hour ago

        $this->assertSame([], array_filter($this->values(), fn (?int $value): bool => $value !== null));
        $this->assertContains(11, $this->values('7d'));
        $this->assertCount(56, $this->values('7d'));
    }

    public function test_an_unknown_range_falls_back_to_a_day(): void
    {
        $this->assertCount(48, $this->values('forever'));
    }

    public function test_the_chart_shows_the_peak_the_latest_and_a_line(): void
    {
        $device = ShopDevice::factory()->for($this->shop)->create();
        $this->sample($device, 30, 100);
        $this->sample($device, 12, 70);
        $this->sample($device, 3, 5);

        Livewire::test(BacklogChart::class, ['shopId' => $this->shop->getKey()])
            ->assertSeeHtml('data-backlog-peak="30"')
            ->assertSeeHtml('data-backlog-latest="3"')
            ->assertSeeHtml('data-backlog-table')
            ->assertDontSeeHtml('data-backlog-empty')
            ->assertSeeHtml('<path d="M')
            ->set('range', '7d')
            ->assertSeeHtml('data-backlog-peak="30"');
    }

    public function test_the_chart_says_so_when_nothing_has_been_reported(): void
    {
        Livewire::test(BacklogChart::class, ['shopId' => $this->shop->getKey()])
            ->assertSeeHtml('data-backlog-empty')
            ->assertDontSeeHtml('data-backlog-peak');
    }

    public function test_the_shop_page_has_the_chart(): void
    {
        $this->get(route('shops.show', $this->shop))->assertOk()->assertSeeLivewire(BacklogChart::class);
    }

    public function test_old_samples_are_pruned(): void
    {
        config(['jocko.retention.backlog_days' => 30, 'jocko.retention.events_days' => 0, 'jocko.retention.audit_days' => 0]);
        $device = ShopDevice::factory()->for($this->shop)->create();
        $this->sample($device, 1, 60 * 24 * 31);
        $this->sample($device, 2, 60 * 24 * 29);

        $this->artisan('jocko:prune')->assertSuccessful();

        $this->assertSame([2], DeviceBacklogSample::query()->pluck('pending_events')->all());
    }
}
