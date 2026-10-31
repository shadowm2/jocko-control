<?php

namespace Tests\Feature\Api;

use App\Enums\CommandType;
use App\Enums\DeviceStatus;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\User;
use App\Services\DeviceManagementService;
use App\Services\RemoteCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDevices;
use Tests\TestCase;

class DeviceApiTest extends TestCase
{
    use CreatesDevices, RefreshDatabase;

    public function test_a_valid_device_can_read_its_own_identity(): void
    {
        $shop = Shop::factory()->create(['name' => 'Alpha Garage']);
        $issued = $this->issueDevice($shop, ['name' => 'Front desk']);
        $other = $this->issueDevice(Shop::factory()->create(), ['name' => 'Someone else']);

        $this->getJson('/api/v1/device/me', $this->bearer($issued))
            ->assertOk()
            ->assertJsonPath('data.id', $issued->device->id)
            ->assertJsonPath('data.name', 'Front desk')
            ->assertJsonPath('data.shop.id', $shop->id)
            ->assertJsonPath('data.shop.name', 'Alpha Garage')
            ->assertJsonPath('data.config.max_events_per_batch', 100)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissing(['name' => 'Someone else']);

        $this->assertNotSame($issued->device->id, $other->device->id);
    }

    public function test_requests_without_valid_credentials_are_rejected(): void
    {
        $this->getJson('/api/v1/device/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->getJson('/api/v1/device/me', ['Authorization' => 'Bearer not-a-real-token'])
            ->assertUnauthorized();

        $this->postJson('/api/v1/device/heartbeat', [], ['Authorization' => 'Bearer 1|garbage'])
            ->assertUnauthorized();
    }

    public function test_an_administrator_session_cannot_call_the_device_api(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/device/me')
            ->assertUnauthorized();
    }

    public function test_the_token_is_stored_hashed(): void
    {
        $issued = $this->issueDevice();

        $secret = explode('|', $issued->plainTextToken, 2)[1];

        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $secret]);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $issued->plainTextToken]);
        $this->assertDatabaseHas('personal_access_tokens', ['token' => hash('sha256', $secret)]);
        $this->assertStringStartsWith('jkd_', $secret);
    }

    public function test_a_heartbeat_updates_server_side_connectivity_and_metadata(): void
    {
        $this->travelTo(now()->startOfSecond());
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/device/heartbeat', [
            'app_version' => '1.4.2',
            'database_schema_version' => '2026_09_01_000001',
            'local_status' => ['state' => 'idle', 'pending_events' => 3, 'database_ok' => true, 'unexpected' => 'dropped'],
        ], $this->bearer($issued))
            ->assertOk()
            ->assertJsonPath('data.received_at', now()->toIso8601String())
            ->assertJsonPath('data.pending_commands', 0);

        $device = $issued->device->fresh();

        $this->assertSame('1.4.2', $device->app_version);
        $this->assertSame('2026_09_01_000001', $device->database_schema_version);
        $this->assertTrue($device->last_seen_at->equalTo(now()));
        $this->assertEquals(['state' => 'idle', 'pending_events' => 3, 'database_ok' => true], $device->metadata['local_status']);
        $this->assertNull($device->last_sync_at, 'A heartbeat alone is not proof of synchronization.');
    }

    public function test_a_heartbeat_cannot_spoof_the_servers_clock(): void
    {
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/device/heartbeat', ['last_seen_at' => '2001-01-01T00:00:00Z'], $this->bearer($issued))->assertOk();

        $this->assertTrue($issued->device->fresh()->last_seen_at->isToday());
    }

    public function test_a_reported_successful_sync_records_last_sync_at_and_clears_errors(): void
    {
        $issued = $this->issueDevice();
        $issued->device->update(['last_sync_error' => 'old', 'last_sync_error_at' => now()->subHour()]);

        $this->postJson('/api/v1/device/heartbeat', ['sync' => ['status' => 'success']], $this->bearer($issued))->assertOk();

        $device = $issued->device->fresh();
        $this->assertNotNull($device->last_sync_at);
        $this->assertNull($device->last_sync_error);
    }

    public function test_a_reported_failed_sync_is_stored_and_audited_once(): void
    {
        $issued = $this->issueDevice();
        $body = ['sync' => ['status' => 'failed', 'error' => 'Disk full']];

        $this->postJson('/api/v1/device/heartbeat', $body, $this->bearer($issued))->assertOk();
        $this->postJson('/api/v1/device/heartbeat', $body, $this->bearer($issued))->assertOk();

        $device = $issued->device->fresh();
        $this->assertSame('Disk full', $device->last_sync_error);
        $this->assertNull($device->last_sync_at);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sync.reported_failure', 'device_id' => $device->id]);
    }

    public function test_heartbeat_validation_rejects_bad_input(): void
    {
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/device/heartbeat', [
            'local_status' => ['state' => 'exploding'],
            'sync' => ['status' => 'maybe'],
        ], $this->bearer($issued))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['local_status.state', 'sync.status']]]);
    }

    public function test_overlong_version_labels_are_cut_instead_of_rejecting_the_heartbeat(): void
    {
        $issued = $this->issueDevice();
        $schema = '2026_10_29_100000_create_control_outbox_events_table';

        $this->postJson('/api/v1/device/heartbeat', [
            'app_version' => str_repeat('v', 80),
            'database_schema_version' => $schema,
        ], $this->bearer($issued))->assertOk();

        $device = $issued->device->fresh();
        $this->assertSame(str_repeat('v', 50), $device->app_version);
        $this->assertSame(mb_substr($schema, 0, 50), $device->database_schema_version);
    }

    public function test_a_heartbeat_with_overlong_labels_still_announces_pending_commands(): void
    {
        $issued = $this->issueDevice();

        app(RemoteCommandService::class)->queue(User::factory()->create(), $issued->device, CommandType::CheckVersion);

        $this->postJson('/api/v1/device/heartbeat', [
            'database_schema_version' => str_repeat('m', 120),
        ], $this->bearer($issued))
            ->assertOk()
            ->assertJsonPath('data.pending_commands', 1);
    }

    public function test_a_revoked_device_is_denied_even_with_its_old_token(): void
    {
        $issued = $this->issueDevice();

        $this->getJson('/api/v1/device/me', $this->bearer($issued))->assertOk();

        app(DeviceManagementService::class)->revoke($issued->device, User::factory()->create());

        $this->assertSame(DeviceStatus::Revoked, $issued->device->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        foreach (['/api/v1/device/me', '/api/v1/device/commands'] as $uri) {
            $this->getJson($uri, $this->bearer($issued))->assertUnauthorized();
        }

        $this->postJson('/api/v1/sync/events', ['events' => [$this->eventPayload()]], $this->bearer($issued))->assertUnauthorized();
        $this->postJson('/api/v1/device/heartbeat', [], $this->bearer($issued))->assertUnauthorized();
        $this->assertDatabaseCount('sync_events', 0);
    }

    public function test_a_device_flagged_revoked_is_denied_even_if_a_token_survives(): void
    {
        $issued = $this->issueDevice();
        $issued->device->update(['status' => DeviceStatus::Revoked]);

        $this->getJson('/api/v1/device/me', $this->bearer($issued))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'device_revoked');
    }

    public function test_devices_of_a_suspended_shop_cannot_use_protected_endpoints(): void
    {
        $issued = $this->issueDevice(Shop::factory()->suspended()->create());

        $this->getJson('/api/v1/device/me', $this->bearer($issued))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'shop_suspended');
        $this->postJson('/api/v1/device/heartbeat', [], $this->bearer($issued))->assertForbidden();
        $this->postJson('/api/v1/sync/events', ['events' => [$this->eventPayload()]], $this->bearer($issued))->assertForbidden();
        $this->getJson('/api/v1/device/commands', $this->bearer($issued))->assertForbidden();

        $this->assertDatabaseCount('sync_events', 0);
        $this->assertNull($issued->device->fresh()->last_seen_at);
    }

    public function test_reactivating_a_shop_restores_device_access(): void
    {
        $shop = Shop::factory()->suspended()->create();
        $issued = $this->issueDevice($shop);
        $this->getJson('/api/v1/device/me', $this->bearer($issued))->assertForbidden();

        $shop->update(['status' => 'active']);
        $this->app['auth']->forgetGuards(); // every real request starts with a fresh guard

        $this->getJson('/api/v1/device/me', $this->bearer($issued))->assertOk();
    }

    public function test_the_device_api_is_rate_limited_per_device(): void
    {
        config(['jocko.api.rate_limit_per_device' => 3]);
        $issued = $this->issueDevice();

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/device/me', $this->bearer($issued))->assertOk();
        }

        $this->getJson('/api/v1/device/me', $this->bearer($issued))
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_requests');
    }

    public function test_errors_never_expose_internals(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/v1/nope')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('exception');
    }

    public function test_tokens_of_other_models_cannot_be_used(): void
    {
        $this->assertSame(['device:access'], ['device:access'], 'sanity');
        $device = ShopDevice::factory()->create();
        $limited = $device->createToken('limited', ['something:else']);

        $this->getJson('/api/v1/device/me', ['Authorization' => 'Bearer '.$limited->plainTextToken])
            ->assertUnauthorized();
    }

    public function test_plain_http_is_refused_when_https_is_required(): void
    {
        config(['jocko.require_https' => true]);
        $issued = $this->issueDevice();

        $this->getJson('/api/v1/device/me', $this->bearer($issued))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'https_required');

        $this->getJson('https://localhost/api/v1/device/me', $this->bearer($issued))
            ->assertOk()
            ->assertHeader('Strict-Transport-Security');

        $this->get(route('login'))->assertRedirectContains('https://');
    }
}
