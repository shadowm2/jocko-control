<?php

namespace Tests\Feature\Admin;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Livewire\Shops\CommandsPanel;
use App\Models\RemoteCommand;
use App\Models\Shop;
use App\Models\User;
use App\Services\RemoteCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesDevices;
use Tests\TestCase;

class CommandManagementTest extends TestCase
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

    public function test_an_administrator_can_queue_a_command_for_a_device(): void
    {
        $this->travelTo(now()->startOfSecond());
        $issued = $this->issueDevice($this->shop);

        Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
            ->set('deviceId', $issued->device->id)
            ->set('commandType', 'check_version')
            ->set('expiresInHours', 6)
            ->call('queue')
            ->assertHasNoErrors()
            ->assertSet('commandType', '');

        $command = RemoteCommand::sole();
        $this->assertSame($this->shop->id, $command->shop_id);
        $this->assertSame($issued->device->id, $command->device_id);
        $this->assertSame(CommandType::CheckVersion, $command->command_type);
        $this->assertSame(CommandStatus::Queued, $command->status);
        $this->assertSame($this->admin->id, $command->requested_by);
        $this->assertTrue($command->expires_at->equalTo(now()->addHours(6)));
        $this->assertNull($command->payload);
        $this->assertDatabaseHas('audit_logs', ['action' => 'command.queued', 'device_id' => $issued->device->id, 'user_id' => $this->admin->id]);
    }

    public function test_parameters_are_validated_and_passed_through(): void
    {
        $issued = $this->issueDevice($this->shop);

        Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
            ->set('deviceId', $issued->device->id)
            ->set('commandType', 'sync_now')
            ->set('full', true)
            ->call('queue')
            ->assertHasNoErrors();

        $this->assertEquals(['full' => true], RemoteCommand::sole()->payload);

        Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
            ->set('deviceId', $issued->device->id)
            ->set('commandType', 'collect_diagnostics')
            ->set('sinceMinutes', 99999)
            ->set('confirmed', true)
            ->call('queue')
            ->assertHasErrors(['sinceMinutes' => 'max']);

        $this->assertDatabaseCount('remote_commands', 1);
    }

    public function test_sensitive_commands_require_explicit_confirmation(): void
    {
        $issued = $this->issueDevice($this->shop);

        foreach (['collect_diagnostics', 'refresh_config'] as $type) {
            Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
                ->set('deviceId', $issued->device->id)
                ->set('commandType', $type)
                ->assertSee(__('ui.commands.sensitive_warning'))
                ->call('queue')
                ->assertHasErrors('confirmed');
        }

        $this->assertDatabaseCount('remote_commands', 0);

        Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
            ->set('deviceId', $issued->device->id)
            ->set('commandType', 'refresh_config')
            ->set('confirmed', true)
            ->call('queue')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('remote_commands', 1);
    }

    public function test_only_allowlisted_command_types_can_be_queued(): void
    {
        $issued = $this->issueDevice($this->shop);

        foreach (['rm -rf /', 'run_sql', 'shell', ''] as $type) {
            Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
                ->set('deviceId', $issued->device->id)
                ->set('commandType', $type)
                ->call('queue')
                ->assertHasErrors('commandType');
        }

        $this->assertDatabaseCount('remote_commands', 0);
        $this->assertSame(['sync_now', 'check_version', 'collect_diagnostics', 'refresh_config'], array_column(CommandType::cases(), 'value'));
    }

    public function test_unexpected_parameters_are_rejected_by_the_service(): void
    {
        $issued = $this->issueDevice($this->shop);

        $this->expectException(ValidationException::class);

        app(RemoteCommandService::class)->queue($this->admin, $issued->device, CommandType::SyncNow, ['command' => 'DROP TABLE']);
    }

    public function test_a_command_must_target_an_active_device_of_this_shop(): void
    {
        $revoked = $this->issueDevice($this->shop, ['status' => 'revoked']);
        $foreign = $this->issueDevice();

        foreach ([$revoked->device->id, $foreign->device->id, 'missing'] as $deviceId) {
            Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
                ->set('deviceId', $deviceId)
                ->set('commandType', 'sync_now')
                ->call('queue')
                ->assertHasErrors('deviceId');
        }

        $this->assertDatabaseCount('remote_commands', 0);
    }

    public function test_commands_cannot_be_queued_for_a_suspended_shop(): void
    {
        $issued = $this->issueDevice($this->shop);
        $this->shop->update(['status' => 'suspended']);

        Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
            ->assertSee(__('ui.commands.shop_suspended'));

        $this->expectException(ValidationException::class);
        app(RemoteCommandService::class)->queue($this->admin, $issued->device, CommandType::SyncNow);
    }

    public function test_the_expiry_must_be_one_of_the_offered_choices(): void
    {
        $issued = $this->issueDevice($this->shop);

        Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
            ->set('deviceId', $issued->device->id)
            ->set('commandType', 'sync_now')
            ->set('expiresInHours', 100000)
            ->call('queue')
            ->assertHasErrors('expiresInHours');

        $this->assertDatabaseCount('remote_commands', 0);
    }

    public function test_the_service_enforces_expiry_bounds(): void
    {
        $issued = $this->issueDevice($this->shop);
        $service = app(RemoteCommandService::class);

        foreach ([now()->subHour(), now()->addSeconds(5), now()->addDays(30)] as $badExpiry) {
            try {
                $service->queue($this->admin, $issued->device, CommandType::SyncNow, [], $badExpiry);
                $this->fail('An out-of-range expiry must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('expiresAt', $e->errors());
            }
        }

        $this->assertDatabaseCount('remote_commands', 0);
    }

    public function test_status_results_and_requesters_are_listed_and_result_is_viewable(): void
    {
        $issued = $this->issueDevice($this->shop, ['name' => 'Front desk']);
        $done = RemoteCommand::factory()->completed()->create([
            'device_id' => $issued->device->id,
            'command_type' => CommandType::CollectDiagnostics,
            'requested_by' => $this->admin->id,
            'result' => ['disk_free_mb' => 4096],
        ]);
        RemoteCommand::factory()->create(['device_id' => $issued->device->id, 'command_type' => CommandType::CheckVersion]);
        RemoteCommand::factory()->create(['device_id' => $this->issueDevice(attributes: ['name' => 'Foreign terminal'])->device->id, 'command_type' => CommandType::RefreshConfig]);

        Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
            ->assertSee(__('ui.command_type.collect_diagnostics'))
            ->assertSee(__('ui.command_status.completed'))
            ->assertSee(__('ui.command_status.queued'))
            ->assertSee($this->admin->name)
            ->assertDontSee('Foreign terminal')
            ->call('showResult', $done->id)
            ->assertSee('disk_free_mb');
    }

    public function test_results_of_other_shops_cannot_be_viewed(): void
    {
        $foreign = RemoteCommand::factory()->completed()->create();

        Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
            ->call('showResult', $foreign->id)
            ->assertNotFound();
    }

    public function test_end_to_end_queue_poll_and_acknowledge(): void
    {
        $issued = $this->issueDevice($this->shop);

        Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
            ->set('deviceId', $issued->device->id)
            ->set('commandType', 'check_version')
            ->call('queue');

        $this->app['auth']->forgetGuards();
        $id = $this->getJson('/api/v1/device/commands', $this->bearer($issued))
            ->assertOk()
            ->assertJsonPath('data.0.type', 'check_version')
            ->json('data.0.id');

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/device/commands/{$id}/ack", ['status' => 'completed', 'result' => ['version' => '1.2.3']], $this->bearer($issued))
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->actingAs($this->admin);

        Livewire::test(CommandsPanel::class, ['shopId' => $this->shop->id])
            ->assertSee(__('ui.command_status.completed'))
            ->call('showResult', $id)
            ->assertSee('1.2.3');
    }
}
