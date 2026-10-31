<?php

namespace Tests\Feature\Admin;

use App\Enums\CommandType;
use App\Events\ShopChanged;
use App\Livewire\Shops\Dashboard;
use App\Models\Shop;
use App\Models\User;
use App\Services\RemoteCommandService;
use App\Services\ShopChangeBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Concerns\BuildsShopMirror;
use Tests\Concerns\CreatesDevices;
use Tests\TestCase;

class LiveShopPagesTest extends TestCase
{
    use BuildsShopMirror, CreatesDevices, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function orderEvent(int $id): array
    {
        return $this->eventPayload([
            'event_type' => 'order.created',
            'aggregate_type' => 'order',
            'base_version' => null,
            'payload' => ['id' => $id, 'order_number' => "ORD-{$id}", 'status' => 'pending', 'created_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')],
        ]);
    }

    public function test_pushing_events_announces_one_data_change_for_that_shop(): void
    {
        Event::fake([ShopChanged::class]);
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/sync/events', ['events' => array_map($this->orderEvent(...), range(1, 25))], $this->bearer($issued))->assertOk();

        Event::assertDispatchedTimes(ShopChanged::class, 1);
        Event::assertDispatched(ShopChanged::class, fn (ShopChanged $event) => $event->shopId === $issued->device->shop_id && $event->scopes === ['data']);
    }

    public function test_events_that_are_not_mirrored_announce_nothing(): void
    {
        Event::fake([ShopChanged::class]);
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/sync/events', ['events' => [$this->eventPayload()]], $this->bearer($issued))->assertOk();

        Event::assertNotDispatched(ShopChanged::class);
    }

    public function test_the_announcement_goes_to_a_private_channel_of_that_shop_only(): void
    {
        $shop = Shop::factory()->create();
        $event = new ShopChanged($shop->getKey(), ['data', 'commands']);

        $this->assertSame(['private-shops.'.$shop->getKey()], array_map(fn ($channel) => $channel->name, $event->broadcastOn()));
        $this->assertSame('ShopChanged', $event->broadcastAs());
        $this->assertSame(['scopes' => ['data', 'commands']], $event->broadcastWith());
    }

    public function test_queueing_and_finishing_a_remote_command_announces_a_commands_change(): void
    {
        Event::fake([ShopChanged::class]);
        $issued = $this->issueDevice();

        app(RemoteCommandService::class)->queue(User::factory()->create(), $issued->device, CommandType::CheckVersion);
        app(ShopChangeBroadcaster::class)->flush();

        Event::assertDispatched(ShopChanged::class, fn (ShopChanged $event) => $event->shopId === $issued->device->shop_id && $event->scopes === ['commands']);
    }

    public function test_a_socket_server_that_is_down_never_breaks_ingestion(): void
    {
        $issued = $this->issueDevice();
        Event::listen(ShopChanged::class, fn () => throw new \RuntimeException('socket server is down'));

        $this->postJson('/api/v1/sync/events', ['events' => [$this->orderEvent(1)]], $this->bearer($issued))
            ->assertOk()
            ->assertJsonPath('meta.accepted', 1);

        $this->assertDatabaseHas('shop_records', ['entity' => 'order', 'local_id' => 1]);
    }

    public function test_only_signed_in_administrators_may_listen_to_a_shop(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        app(BroadcastManager::class)->purge();
        // The channels were registered on the broadcaster that was active at boot.
        require base_path('routes/channels.php');
        $shop = Shop::factory()->create();
        $request = ['channel_name' => 'private-shops.'.$shop->getKey(), 'socket_id' => '1234.5678'];

        $this->post('/broadcasting/auth', $request, ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAs(User::factory()->create(['is_active' => false]))
            ->post('/broadcasting/auth', $request, ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post('/broadcasting/auth', $request, ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_a_page_refreshes_for_a_change_it_shows_and_ignores_one_it_does_not(): void
    {
        $this->actingAs(User::factory()->create());
        $shop = Shop::factory()->create();
        $this->seedShop($shop);

        $page = Livewire::test(Dashboard::class, ['shop' => $shop])->assertDontSee('ORD-99');

        $this->mirror($shop, 'order', 99, ['order_number' => 'ORD-99', 'status' => 'pending', 'user_id' => 1, 'user_car_id' => 1, 'warehouse_id' => 1, 'created_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')], 'ORD-99');

        $page->call('refreshLive', ['scopes' => ['commands']])->assertDontSee('ORD-99');
        $page->call('refreshLive', ['scopes' => ['data']])->assertSee('ORD-99');
    }

    public function test_the_pages_show_whether_they_are_live(): void
    {
        $this->actingAs(User::factory()->create());
        $shop = Shop::factory()->create();

        $this->get(route('shops.dashboard', $shop))->assertOk()->assertSee('data-live-indicator', false)->assertSee(__('ui.live.live'));
        $this->get(route('shops.show', $shop))->assertOk()->assertSee('echo-private:shops.'.$shop->getKey().',.ShopChanged', false);
    }
}
