<?php

namespace Tests\Feature\Admin;

use App\Enums\DeviceStatus;
use App\Livewire\Shops\DevicesPanel;
use App\Models\AuditLog;
use App\Models\RemoteCommand;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\SyncEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesDevices;
use Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    use CreatesDevices, RefreshDatabase;

    private User $admin;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->shop = Shop::factory()->create();
        $this->actingAs($this->admin);
    }

    /**
     * A device API call switches the default guard for the rest of the process;
     * real requests never share one, so reset it between the two kinds of call.
     */
    private function backToAdministrator(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->actingAs($this->admin);
    }

    public function test_an_administrator_can_register_several_devices_for_a_shop(): void
    {
        Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
            ->set('deviceName', 'Front desk')
            ->call('register')
            ->assertHasNoErrors()
            ->set('deviceName', 'Workshop')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertSame(['Front desk', 'Workshop'], $this->shop->devices()->orderBy('name')->pluck('name')->all());
        $this->assertSame(2, AuditLog::where('action', 'device.created')->where('shop_id', $this->shop->id)->count());
    }

    public function test_a_device_name_is_required(): void
    {
        Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
            ->set('deviceName', '')
            ->call('register')
            ->assertHasErrors(['deviceName' => 'required']);

        $this->assertDatabaseCount('shop_devices', 0);
    }

    public function test_the_token_is_shown_once_and_works_for_the_api(): void
    {
        $component = Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
            ->set('deviceName', 'Front desk')
            ->call('register');

        $token = $component->get('issuedToken');

        $this->assertStringContainsString('|jkd_', $token);
        $component->assertSee($token)->assertSet('issuedForName', 'Front desk');

        $this->getJson('/api/v1/device/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.name', 'Front desk');
        $this->backToAdministrator();

        // Once the dialog is dismissed, the token is gone from component state.
        $component->call('dismissToken')
            ->assertSet('issuedToken', null)
            ->assertDontSee($token);
    }

    public function test_the_plaintext_token_is_never_persisted_or_audited(): void
    {
        $component = Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
            ->set('deviceName', 'Front desk')
            ->call('register');

        $token = $component->get('issuedToken');
        $secret = explode('|', $token, 2)[1];

        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $secret]);
        $this->assertDatabaseHas('personal_access_tokens', ['token' => hash('sha256', $secret)]);
        $this->assertSame(0, AuditLog::where('metadata', 'like', '%'.$secret.'%')->count());
        $this->assertSame(0, \DB::table('shop_devices')->where('metadata', 'like', '%'.$secret.'%')->count());
    }

    public function test_reloading_the_shop_page_never_reveals_the_token(): void
    {
        $token = Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
            ->set('deviceName', 'Front desk')
            ->call('register')
            ->get('issuedToken');

        $secret = explode('|', $token, 2)[1];

        $this->get(route('shops.show', $this->shop))
            ->assertOk()
            ->assertSee('Front desk')
            ->assertDontSee($secret)
            ->assertDontSee($token);
    }

    public function test_a_fresh_component_has_no_token_and_the_token_property_cannot_be_set_by_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
            ->assertSet('issuedToken', null)
            ->set('issuedToken', 'forged');
    }

    public function test_revoking_a_device_blocks_its_old_token(): void
    {
        $issued = $this->issueDevice($this->shop, ['name' => 'Front desk']);
        $this->getJson('/api/v1/device/me', $this->bearer($issued))->assertOk();
        $this->backToAdministrator();

        Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
            ->call('confirmRevoke', $issued->device->id)
            ->assertSet('targetDeviceId', $issued->device->id)
            ->call('revoke')
            ->assertSet('targetDeviceId', null);

        $device = $issued->device->fresh();
        $this->assertSame(DeviceStatus::Revoked, $device->status);
        $this->assertNotNull($device->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.revoked', 'device_id' => $device->id, 'user_id' => $this->admin->id]);

        $this->getJson('/api/v1/device/me', $this->bearer($issued))->assertUnauthorized();
    }

    public function test_rotating_a_token_invalidates_the_previous_one(): void
    {
        $issued = $this->issueDevice($this->shop);

        $component = Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
            ->call('confirmRotate', $issued->device->id)
            ->call('rotate');

        $new = $component->get('issuedToken');
        $this->assertNotSame($issued->plainTextToken, $new);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.token_rotated', 'device_id' => $issued->device->id]);

        $this->getJson('/api/v1/device/me', $this->bearer($issued))->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/device/me', ['Authorization' => 'Bearer '.$new])->assertOk();
    }

    public function test_a_revoked_device_cannot_be_rotated_back_to_life(): void
    {
        $issued = $this->issueDevice($this->shop, ['status' => DeviceStatus::Revoked]);

        Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
            ->call('confirmRotate', $issued->device->id)
            ->call('rotate')
            ->assertSet('issuedToken', null)
            ->assertHasErrors('device');
    }

    public function test_devices_of_another_shop_cannot_be_managed_through_this_panel(): void
    {
        $foreign = $this->issueDevice();

        foreach (['confirmRevoke', 'confirmRotate', 'showActivity'] as $action) {
            Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
                ->call($action, $foreign->device->id)
                ->assertNotFound();
        }

        $this->assertSame(DeviceStatus::Active, $foreign->device->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_the_panel_shows_status_version_and_sync_health(): void
    {
        $this->travelTo(now()->startOfSecond());

        ShopDevice::factory()->for($this->shop)->create(['name' => 'Never seen']);
        ShopDevice::factory()->for($this->shop)->seenAt(now()->subMinute())->create(['name' => 'Fresh', 'app_version' => '2.1.0', 'last_sync_at' => now()->subMinute()]);
        ShopDevice::factory()->for($this->shop)->seenAt(now()->subHour())->withSyncError('Disk full')->create(['name' => 'Stale']);
        ShopDevice::factory()->for($this->shop)->revoked()->create(['name' => 'Gone']);

        Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
            ->assertSeeInOrder(['Never seen', __('ui.connectivity.never_connected')])
            ->assertSeeInOrder(['Fresh', __('ui.connectivity.online'), '2.1.0', __('ui.devices.sync_ok')])
            ->assertSeeInOrder(['Stale', __('ui.connectivity.offline'), __('ui.devices.sync_error'), 'Disk full'])
            ->assertSeeInOrder(['Gone', __('ui.connectivity.revoked')]);
    }

    public function test_device_activity_lists_recent_events_commands_and_audit_entries(): void
    {
        $issued = $this->issueDevice($this->shop, ['name' => 'Front desk']);
        SyncEvent::factory()->create(['device_id' => $issued->device->id, 'event_type' => 'invoice.paid']);
        RemoteCommand::factory()->create(['device_id' => $issued->device->id]);

        Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
            ->call('showActivity', $issued->device->id)
            ->assertSee('invoice.paid')
            ->assertSee(__('ui.command_type.sync_now'));
    }

    public function test_the_missing_device_lookup_is_a_404(): void
    {
        Livewire::test(DevicesPanel::class, ['shopId' => $this->shop->id])
            ->call('confirmRevoke', (string) Str::uuid())
            ->assertNotFound();
    }
}
