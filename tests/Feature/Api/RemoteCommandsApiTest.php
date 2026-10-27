<?php

namespace Tests\Feature\Api;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Models\RemoteCommand;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesDevices;
use Tests\TestCase;

class RemoteCommandsApiTest extends TestCase
{
    use CreatesDevices, RefreshDatabase;

    public function test_a_device_receives_only_its_own_unexpired_queued_commands(): void
    {
        $shop = Shop::factory()->create();
        $mine = $this->issueDevice($shop);
        $sibling = $this->issueDevice($shop);
        $foreign = $this->issueDevice();

        $own = RemoteCommand::factory()->create(['device_id' => $mine->device->id, 'command_type' => CommandType::CheckVersion]);
        RemoteCommand::factory()->create(['device_id' => $sibling->device->id]);
        RemoteCommand::factory()->create(['device_id' => $foreign->device->id]);
        RemoteCommand::factory()->expired()->create(['device_id' => $mine->device->id]);
        RemoteCommand::factory()->completed()->create(['device_id' => $mine->device->id]);

        $response = $this->getJson('/api/v1/device/commands', $this->bearer($mine))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.type', 'check_version');

        $this->assertArrayNotHasKey('requested_by', $response->json('data.0'));
        $this->assertArrayNotHasKey('shop_id', $response->json('data.0'));
    }

    public function test_delivery_is_tracked_and_the_batch_is_limited(): void
    {
        $mine = $this->issueDevice();
        RemoteCommand::factory()->count(5)->create(['device_id' => $mine->device->id]);

        $this->getJson('/api/v1/device/commands?limit=2', $this->bearer($mine))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame(2, RemoteCommand::whereNotNull('delivered_at')->count());
        $this->assertSame(2, (int) RemoteCommand::where('delivery_count', 1)->count());

        $this->getJson('/api/v1/device/commands?limit=500', $this->bearer($mine))->assertUnprocessable();
    }

    public function test_polling_marks_overdue_commands_as_expired(): void
    {
        $mine = $this->issueDevice();
        $overdue = RemoteCommand::factory()->expired()->create(['device_id' => $mine->device->id]);

        $this->getJson('/api/v1/device/commands', $this->bearer($mine))->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame(CommandStatus::Expired, $overdue->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'command.expired', 'device_id' => $mine->device->id]);
    }

    public function test_a_device_can_complete_its_command(): void
    {
        $mine = $this->issueDevice();
        $command = RemoteCommand::factory()->create(['device_id' => $mine->device->id]);

        $this->postJson("/api/v1/device/commands/{$command->id}/ack", [
            'status' => 'completed',
            'result' => ['synced_events' => 12],
        ], $this->bearer($mine))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.already_acknowledged', false);

        $command->refresh();
        $this->assertSame(CommandStatus::Completed, $command->status);
        $this->assertNotNull($command->completed_at);
        $this->assertEquals(['synced_events' => 12], $command->result);
        $this->assertDatabaseHas('audit_logs', ['action' => 'command.completed', 'device_id' => $mine->device->id]);

        $this->getJson('/api/v1/device/commands', $this->bearer($mine))->assertJsonCount(0, 'data');
    }

    public function test_a_device_can_report_failure_with_an_error_message(): void
    {
        $mine = $this->issueDevice();
        $command = RemoteCommand::factory()->create(['device_id' => $mine->device->id]);

        $this->postJson("/api/v1/device/commands/{$command->id}/ack", ['status' => 'failed', 'error' => 'Disk full'], $this->bearer($mine))
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertEquals(['error' => 'Disk full'], $command->fresh()->result);
        $this->assertDatabaseHas('audit_logs', ['action' => 'command.failed']);
    }

    public function test_repeating_an_identical_acknowledgment_is_harmless(): void
    {
        $mine = $this->issueDevice();
        $command = RemoteCommand::factory()->create(['device_id' => $mine->device->id]);
        $body = ['status' => 'completed', 'result' => ['n' => 1]];

        $this->postJson("/api/v1/device/commands/{$command->id}/ack", $body, $this->bearer($mine))->assertOk();
        $completedAt = $command->fresh()->completed_at;

        $this->travel(5)->minutes();

        $this->postJson("/api/v1/device/commands/{$command->id}/ack", ['status' => 'completed', 'result' => ['n' => 999]], $this->bearer($mine))
            ->assertOk()
            ->assertJsonPath('data.already_acknowledged', true);

        $fresh = $command->fresh();
        $this->assertTrue($completedAt->equalTo($fresh->completed_at));
        $this->assertEquals(['n' => 1], $fresh->result, 'The first result must not be overwritten.');
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_contradicting_a_final_acknowledgment_is_a_conflict(): void
    {
        $mine = $this->issueDevice();
        $command = RemoteCommand::factory()->create(['device_id' => $mine->device->id]);

        $this->postJson("/api/v1/device/commands/{$command->id}/ack", ['status' => 'completed'], $this->bearer($mine))->assertOk();

        $this->postJson("/api/v1/device/commands/{$command->id}/ack", ['status' => 'failed'], $this->bearer($mine))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'command_already_finalized');

        $this->assertSame(CommandStatus::Completed, $command->fresh()->status);
    }

    public function test_an_expired_command_cannot_become_completed(): void
    {
        $mine = $this->issueDevice();

        $overdue = RemoteCommand::factory()->expired()->create(['device_id' => $mine->device->id]);
        $this->postJson("/api/v1/device/commands/{$overdue->id}/ack", ['status' => 'completed'], $this->bearer($mine))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'command_expired');
        $this->assertSame(CommandStatus::Expired, $overdue->fresh()->status);
        $this->assertNull($overdue->fresh()->completed_at);

        // Already swept to expired earlier: still refused.
        $this->postJson("/api/v1/device/commands/{$overdue->id}/ack", ['status' => 'completed'], $this->bearer($mine))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'command_expired');
    }

    public function test_a_device_cannot_acknowledge_another_devices_command(): void
    {
        $shop = Shop::factory()->create();
        $mine = $this->issueDevice($shop);
        $sibling = $this->issueDevice($shop);
        $foreign = $this->issueDevice();

        foreach ([$sibling, $foreign] as $owner) {
            $command = RemoteCommand::factory()->create(['device_id' => $owner->device->id]);

            $this->postJson("/api/v1/device/commands/{$command->id}/ack", ['status' => 'completed'], $this->bearer($mine))
                ->assertNotFound()
                ->assertJsonPath('error.code', 'command_not_found');

            $this->assertSame(CommandStatus::Queued, $command->fresh()->status);
        }
    }

    public function test_acknowledgment_validation(): void
    {
        $mine = $this->issueDevice();
        $command = RemoteCommand::factory()->create(['device_id' => $mine->device->id]);

        $this->postJson("/api/v1/device/commands/{$command->id}/ack", [], $this->bearer($mine))->assertUnprocessable();
        $this->postJson("/api/v1/device/commands/{$command->id}/ack", ['status' => 'queued'], $this->bearer($mine))->assertUnprocessable();
        $this->postJson("/api/v1/device/commands/{$command->id}/ack", ['status' => 'expired'], $this->bearer($mine))->assertUnprocessable();
        $this->postJson("/api/v1/device/commands/{$command->id}/ack", ['status' => 'completed', 'result' => 'text'], $this->bearer($mine))->assertUnprocessable();

        config(['jocko.commands.max_result_bytes' => 50]);
        $this->postJson("/api/v1/device/commands/{$command->id}/ack", ['status' => 'completed', 'result' => ['blob' => str_repeat('x', 200)]], $this->bearer($mine))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['result']]]);

        $this->postJson('/api/v1/device/commands/not-a-uuid/ack', ['status' => 'completed'], $this->bearer($mine))->assertNotFound();
        $this->postJson('/api/v1/device/commands/'.Str::uuid().'/ack', ['status' => 'completed'], $this->bearer($mine))->assertNotFound();

        $this->assertSame(CommandStatus::Queued, $command->fresh()->status);
    }

    public function test_the_heartbeat_reports_the_number_of_pending_commands(): void
    {
        $mine = $this->issueDevice();
        RemoteCommand::factory()->count(2)->create(['device_id' => $mine->device->id]);
        RemoteCommand::factory()->expired()->create(['device_id' => $mine->device->id]);

        $this->postJson('/api/v1/device/heartbeat', [], $this->bearer($mine))
            ->assertOk()
            ->assertJsonPath('data.pending_commands', 2);
    }

    public function test_the_expire_command_sweeps_overdue_commands(): void
    {
        $mine = $this->issueDevice();
        RemoteCommand::factory()->expired()->count(3)->create(['device_id' => $mine->device->id]);
        $live = RemoteCommand::factory()->create(['device_id' => $mine->device->id]);

        $this->artisan('jocko:expire-commands')->expectsOutputToContain('Expired 3')->assertSuccessful();

        $this->assertSame(3, RemoteCommand::where('status', 'expired')->count());
        $this->assertSame(CommandStatus::Queued, $live->fresh()->status);

        $this->artisan('jocko:expire-commands')->expectsOutputToContain('Expired 0')->assertSuccessful();
    }
}
