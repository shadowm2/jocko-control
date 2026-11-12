<?php

namespace Tests\Feature\Admin;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Livewire\Dashboard;
use App\Livewire\Shops\Show;
use App\Models\RemoteCommand;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\User;
use App\Services\DeviceManagementService;
use App\Services\IssuedDevice;
use App\Services\MirrorCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsShopMirror;
use Tests\Concerns\CreatesDevices;
use Tests\TestCase;

class MirrorCheckTest extends TestCase
{
    use BuildsShopMirror, CreatesDevices, RefreshDatabase;

    private Shop $shop;

    private ShopDevice $device;

    private int $next = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        $this->device = $this->issueDevice($this->shop, ['last_seen_at' => now()])->device;
    }

    /**
     * @param  array<string, array{alive: int, removed: int}>  $counts
     * @return array<string, mixed>
     */
    private function report(array $counts): array
    {
        $this->postJson('/api/v1/device/heartbeat', ['record_counts' => $counts], $this->bearer($this->issueDeviceFor()))->assertOk();

        return $this->device->fresh()->metadata['mirror'] ?? [];
    }

    private function issueDeviceFor(): IssuedDevice
    {
        $token = $this->device->createToken('device-access', [DeviceManagementService::ABILITY]);

        return new IssuedDevice($this->device, $token->plainTextToken);
    }

    private function signInAdmin(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->actingAs(User::factory()->create());
    }

    public function test_a_matching_report_is_ok(): void
    {
        $this->mirror($this->shop, 'brand', $this->next++, ['name' => 'Bosch']);
        $this->mirror($this->shop, 'brand', $this->next++, ['name' => 'Mann'], removed: true);

        $mirror = $this->report(['brand' => ['alive' => 1, 'removed' => 1], 'order' => ['alive' => 0, 'removed' => 0]]);

        $this->assertSame('ok', $mirror['status']);
        $this->assertSame('ok', app(MirrorCheck::class)->check($this->shop)['status']);
    }

    public function test_missing_records_are_found_and_can_be_asked_for_again(): void
    {
        $this->mirror($this->shop, 'brand', $this->next++, ['name' => 'Bosch']);

        $mirror = $this->report(['brand' => ['alive' => 3, 'removed' => 0], 'order' => ['alive' => 2, 'removed' => 0]]);

        $this->assertSame('drift', $mirror['status']);
        $this->assertSame(4, $mirror['missing']);
        $this->assertEqualsCanonicalizing(['brand', 'order'], $mirror['resendable']);
    }

    public function test_extra_records_are_reported_but_cannot_be_resent(): void
    {
        $this->mirror($this->shop, 'brand', $this->next++, ['name' => 'Bosch']);
        $this->mirror($this->shop, 'brand', $this->next++, ['name' => 'Mann']);

        $mirror = $this->report(['brand' => ['alive' => 1, 'removed' => 0]]);

        $this->assertSame('drift', $mirror['status']);
        $this->assertSame([0, 1], [$mirror['missing'], $mirror['extra']]);
        $this->assertSame([], $mirror['resendable']);
    }

    public function test_a_shop_that_never_reported_is_unknown(): void
    {
        $check = app(MirrorCheck::class)->check($this->shop);

        $this->assertSame('unknown', $check['status']);
        $this->assertNull($check['device']);
    }

    public function test_a_bad_report_is_refused(): void
    {
        $this->postJson('/api/v1/device/heartbeat', ['record_counts' => ['brand' => ['alive' => -1, 'removed' => 0]]], $this->bearer($this->issueDeviceFor()))
            ->assertUnprocessable();
    }

    public function test_odd_entity_names_are_dropped(): void
    {
        $this->report(['brand' => ['alive' => 0, 'removed' => 0], 'Bad Name!' => ['alive' => 9, 'removed' => 0]]);

        $this->assertSame(['brand'], array_keys($this->device->fresh()->metadata['record_counts']));
    }

    public function test_the_shop_page_shows_the_gap_and_asks_for_the_missing_types_again(): void
    {
        $this->report(['brand' => ['alive' => 3, 'removed' => 0]]);
        $this->signInAdmin();

        Livewire::test(Show::class, ['shop' => $this->shop])
            ->assertSee('data-mirror="drift"', false)
            ->assertSee('data-mirror-row="brand"', false)
            ->call('resendMissing');

        $command = RemoteCommand::sole();
        $this->assertSame(CommandType::SyncNow, $command->command_type);
        $this->assertSame(['entities' => ['brand']], $command->payload);
        $this->assertSame(CommandStatus::Queued, $command->status);
    }

    public function test_nothing_is_queued_when_there_is_nothing_to_send_again(): void
    {
        $this->report(['brand' => ['alive' => 0, 'removed' => 0]]);
        $this->signInAdmin();

        Livewire::test(Show::class, ['shop' => $this->shop])
            ->assertSee('data-mirror="ok"', false)
            ->assertDontSee('data-resend-missing', false)
            ->call('resendMissing');

        $this->assertSame(0, RemoteCommand::count());
    }

    public function test_the_dashboard_lists_the_shops_that_are_out_of_step(): void
    {
        $this->report(['brand' => ['alive' => 5, 'removed' => 0]]);
        $this->signInAdmin();

        $healthy = Shop::factory()->create(['name' => 'Healthy Garage']);
        $this->issueDevice($healthy, ['last_seen_at' => now()]);

        Livewire::test(Dashboard::class)
            ->assertSee('data-mirror-drift', false)
            ->assertSee($this->shop->name)
            ->assertDontSee('Healthy Garage');
    }
}
