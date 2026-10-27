<?php

namespace Tests\Feature;

use App\Enums\CommandStatus;
use App\Enums\DeviceStatus;
use App\Enums\ShopStatus;
use App\Models\AuditLog;
use App\Models\RemoteCommand;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\SyncEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationships_connect_shops_devices_events_commands_and_audit_logs(): void
    {
        $shop = Shop::factory()->create();
        $device = ShopDevice::factory()->for($shop)->create();
        $event = SyncEvent::factory()->create(['device_id' => $device->id]);
        $admin = User::factory()->create();
        $command = RemoteCommand::factory()->create(['device_id' => $device->id, 'requested_by' => $admin->id]);
        $log = AuditLog::create(['user_id' => $admin->id, 'shop_id' => $shop->id, 'device_id' => $device->id, 'action' => 'shop.created']);

        $this->assertTrue($shop->devices->first()->is($device));
        $this->assertTrue($device->shop->is($shop));
        $this->assertTrue($shop->syncEvents->first()->is($event));
        $this->assertTrue($device->syncEvents->first()->is($event));
        $this->assertTrue($event->shop->is($shop));
        $this->assertTrue($event->device->is($device));
        $this->assertTrue($shop->remoteCommands->first()->is($command));
        $this->assertTrue($device->remoteCommands->first()->is($command));
        $this->assertTrue($command->requester->is($admin));
        $this->assertTrue($shop->auditLogs->first()->is($log));
        $this->assertTrue($device->auditLogs->first()->is($log));
        $this->assertTrue($log->user->is($admin));
    }

    public function test_primary_keys_are_uuids_and_enums_are_cast(): void
    {
        $device = ShopDevice::factory()->create();
        $command = RemoteCommand::factory()->create(['device_id' => $device->id]);

        $this->assertTrue(Str::isUuid($device->shop->id));
        $this->assertTrue(Str::isUuid($device->id));
        $this->assertTrue(Str::isUuid($command->id));
        $this->assertSame(ShopStatus::Active, $device->shop->status);
        $this->assertSame(DeviceStatus::Active, $device->status);
        $this->assertSame(CommandStatus::Queued, $command->status);

        $id = (string) Str::uuid();
        Shop::query()->toBase()->insert(['id' => $id, 'name' => 'Bare shop', 'slug' => 'bare-shop']);

        $this->assertSame('Asia/Tehran', Shop::find($id)->timezone);
        $this->assertSame(ShopStatus::Active, Shop::find($id)->status);
    }

    public function test_a_device_cannot_reference_a_missing_shop(): void
    {
        $this->expectException(QueryException::class);

        ShopDevice::factory()->create(['shop_id' => (string) Str::uuid()]);
    }

    public function test_events_commands_and_audit_logs_cannot_reference_missing_owners(): void
    {
        $device = ShopDevice::factory()->create();

        foreach ([
            fn () => SyncEvent::factory()->create(['device_id' => $device->id, 'shop_id' => (string) Str::uuid()]),
            fn () => SyncEvent::factory()->create(['device_id' => (string) Str::uuid(), 'shop_id' => $device->shop_id]),
            fn () => RemoteCommand::factory()->create(['device_id' => $device->id, 'shop_id' => (string) Str::uuid()]),
            fn () => RemoteCommand::factory()->create(['device_id' => (string) Str::uuid(), 'shop_id' => $device->shop_id]),
            fn () => RemoteCommand::factory()->create(['device_id' => $device->id, 'requested_by' => 999999]),
            fn () => AuditLog::create(['shop_id' => (string) Str::uuid(), 'action' => 'shop.created']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A foreign key violation was expected.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key', strtolower($e->getMessage()));
            }
        }
    }

    public function test_a_shop_with_history_cannot_be_deleted(): void
    {
        $device = ShopDevice::factory()->create();

        $this->expectException(QueryException::class);

        $device->shop->delete();
    }

    public function test_event_ids_are_unique_in_the_database(): void
    {
        $first = SyncEvent::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        SyncEvent::factory()->create(['event_id' => $first->event_id]);
    }

    public function test_shop_slugs_are_unique_in_the_database(): void
    {
        Shop::factory()->create(['slug' => 'alpha']);

        $this->expectException(UniqueConstraintViolationException::class);

        Shop::factory()->create(['slug' => 'alpha']);
    }

    public function test_event_timestamps_keep_millisecond_precision(): void
    {
        $event = SyncEvent::factory()->create(['occurred_at' => '2026-03-04 05:06:07.123']);

        $this->assertSame('123', $event->fresh()->occurred_at->format('v'));
    }

    public function test_connectivity_is_derived_from_server_side_timestamps(): void
    {
        $this->travelTo(now()->startOfSecond());
        $active = Shop::factory()->create();

        $never = ShopDevice::factory()->for($active)->create();
        $online = ShopDevice::factory()->for($active)->seenAt(now()->subMinutes(14))->create();
        $offline = ShopDevice::factory()->for($active)->seenAt(now()->subMinutes(16))->create();
        $revoked = ShopDevice::factory()->for($active)->revoked()->seenAt(now())->create();
        $suspended = ShopDevice::factory()->for(Shop::factory()->suspended())->seenAt(now())->create();

        $this->assertSame('never_connected', $never->connectivity()->value);
        $this->assertSame('online', $online->connectivity()->value);
        $this->assertSame('offline', $offline->connectivity()->value);
        $this->assertSame('revoked', $revoked->connectivity()->value);
        $this->assertSame('shop_suspended', $suspended->connectivity()->value);

        $this->assertSame([$online->id], ShopDevice::online()->pluck('id')->all());
        $this->assertSame([$offline->id], ShopDevice::offline()->pluck('id')->all());
        $this->assertSame([$never->id], ShopDevice::neverConnected()->pluck('id')->all());

        config(['jocko.online_threshold_minutes' => 30]);
        $this->assertSame('online', $offline->fresh()->connectivity()->value);
    }
}
